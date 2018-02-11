<?php
echo "KOUKOU\n";

ini_set("memory_limit", "300M");
// require_once('DemoMove.php');

$play = new Play();
//$play = new Play($fast = true, $debug = true);

$json = '';
$date = date('ymd-His', time());
while(1) {
    // 入力値をUTF-8に変換する
    $input = fgets(STDIN);
    $json .= $input;
    if (PHP_OS === 'WINNT' && mb_check_encoding($input, 'SJIS')) {
        $input = mb_convert_encoding($input, "UTF-8", "SJIS");
    }

    $json_obj = json_decode($input);

    if (isset($json_obj)) {
        // file_put_contents('json/'. $date . ".json", $input, FILE_APPEND);
        $play->action($json_obj);
    } else {
        // file_put_contents('json/'. $date . ".json", $json);
        exit;
    }
}

class Play {
    private $fast;
    private $map_mode;
    private $battle_mode;

    public function __construct($fast = false, $debug = false) {
        if ($debug) {
            set_error_handler(function ($errno, $errstr, $errfile, $errline) {
                throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
            });
        }

        $this->map_mode = new MapMode($fast);
        $this->battle_mode = new BattleMode($fast);
        $this->fast = $fast;
    }

    public function action($json_obj) {
        if (property_exists($json_obj, 'map')) {
            // マップ移動モードのとき
            // 攻撃回数のリセット
            $this->battle_mode->reset();

            if ($this->map_mode->shouldReload) {
                $this->map_mode->shouldReload = false;
                $this->map_mode->reload($json_obj);
            }

            $act = $this->map_mode->move($json_obj->player);

            $this->map_mode->update($act);

            // TODO
            // DEMO
            // $demo = new DemoMove($this->map_mode->tate, $this->map_mode->yoko);
            // $demo->showRoute($this->map_mode->masu, $this->map_mode->player_pos, $this->map_mode->record, 100000);

            echo $act . "\n";
            return;
        } else if (property_exists($json_obj, 'battle')) {
            // バトルモードのとき
            $act = $this->battle_mode->move($json_obj);

            // ターンを進める
            $this->battle_mode->done();

            echo $act . "\n";
            return;
        } else if (property_exists($json_obj, 'equip')) {
            // 装備モードのとき
            if (
                $json_obj->discover->str + $json_obj->discover->hp + $json_obj->discover->agi * 1.5
                > $json_obj->now->str + $json_obj->now->hp + $json_obj->now->agi * 1.5
            ) {
                echo "YES\n";
            } else {
                echo "NO\n";
            }
            return;
        } else if (property_exists($json_obj, 'levelup')) {
            // レベルアップモードのとき
            if ($json_obj->player->maxagi > 50){
                if ($json_obj->player->maxhp < $json_obj->player->maxstr && $json_obj->player->maxhp < $json_obj->player->maxagi) {
                    echo "HP\n";
                    return;
                } else if ($json_obj->player->maxstr < $json_obj->player->maxhp && $json_obj->player->maxstr < $json_obj->player->maxagi) {
                    echo "STR\n";
                    return;
                } else {
                    echo "AGI\n";
                    return;
                }
            } else if ($json_obj->player->maxhp - $json_obj->player->buki[2] / 2 > $json_obj->player->maxagi - $json_obj->player->buki[3] / 2) {
                echo "AGI\n";
                return;
            } else {
                echo "HP\n";
                return;
            }
        } else {
            // 攻撃回数のリセット
            $this->battle_mode->reset();
            return;
        }
    }
}

class BattleMode {
    private $fast;
    private $turn;
    private $monsters;

    public function __construct($fast = false) {
        $this->fast = $fast;
        $this->turn = 0;
        $this->monsters = new Monsters();
    }

    public function reset() {
        $this->turn = 0;
    }

    public function done() {
        $this->turn = $this->turn + 1;
    }

    public function move($json_obj) {
        // オブジェクトのコピー
        $this->monsters->init($json_obj->monsters);
        // 攻撃対象の抽出
        $this->monsters->pickMonsters($json_obj->player);

        if ($this->kaifuku($json_obj->player, $this->monsters, $this->turn)) {
            return 'HEAL';
        }

        return $this->battle($json_obj->player);
    }

    private function kaifuku($player, $monsters, $turn) {
        if ($player->heal === 0) {
            return false;
        }

        $times = 1 + (int)($player->agi / 15) - $turn;

        $damage = $monsters->damage($player);

        if ($player->str < $player->maxstr * 0.7 && $player->hp <= $damage && $player->hp < $player->maxhp) {
            return true;
        }

        if ($times > 1) {
            if ($player->str < $player->maxstr * 0.5 && $player->hp < $player->maxhp) {
                return true;
            }
        }

        if ($times === 1) {
            if ($player->str < $player->maxstr * 0.4 && $player->hp < $player->maxhp * 0.4) {
                return true;
            }
            if ($player->hp <= $damage && $player->hp < $player->maxhp * 0.9) {
                return true;
            }

        }

        return false;
    }

    private function battle($player) {
        $monsters = $this->monsters;

        if (count($monsters->targets) === 1) {
            return 'STAB ' . $monsters->targets[0]->number;
        }
        // 敵が1体しかいないとき
        if (count($monsters->aliveMonsters) === 1) {
            return 'STAB ' . $monsters->aliveMonsters[0]->number;
        }

        $times = 1 + (int)($player->agi / 15) - $this->turn;

        $score = [];
        $pattern = $monsters->createAllPattern($player, true);
        for ($i = 0; $i < 3; $i++) {
            $score = $this->playout($player, $pattern, $score);

            $avg = [];
            $dsort = [];
            $psort = [];
            foreach ($score as $k => $v) {
                $avg[] = [(string)$k, $v[0] / $v[2], $v[1] / $v[2], $v[2]];
                $dsort[$k] = $v[0] / $v[2];
                $psort[$k] = $v[1] / $v[2];
                $csort[$k] = $v[2];
            }

            // 同じ敵だけを攻撃し続けたとき
            if (count($avg) === 1) {
                return 'STAB ' . $avg[0][0];
            }

            // 攻撃パターンを絞る
            $score = [];
            $pattern = [];
            $tmp = $avg;
            array_multisort($dsort, SORT_ASC, $avg);
            if ($avg[0][1] !== $avg[1][1]) {
                // 死亡率が違う場合
                for ($j = 0; $j <= count($avg) / 2; $j++) {
                    $score[$avg[$j][0]] = [$avg[$j][1] * $avg[$j][3], $avg[$j][2] * $avg[$j][3], $avg[$j][3]];
                    $t = explode(',', $avg[$j][0]);
                    if (count($t) === 1) {
                        $pattern[] = [(int)$t[0]];
                    } else {
                        $pattern[] = [(int)$t[0], (int)$t[1]];
                    }
                }
                continue;
            }
            $avg = $tmp;
            array_multisort($psort, SORT_DESC, $avg);
            if ($avg[0][2] !== $avg[1][2]) {
                // 評価値が違う場合
                for ($j = 0; $j <= count($avg) / 2; $j++) {
                    $score[$avg[$j][0]] = [$avg[$j][1] * $avg[$j][3], $avg[$j][2] * $avg[$j][3], $avg[$j][3]];
                    $t = explode(',', $avg[$j][0]);
                    if (count($t) === 1) {
                        $pattern[] = [(int)$t[0]];
                    } else {
                        $pattern[] = [(int)$t[0], (int)$t[1]];
                    }
                }
                continue;
            } else {
                // やけくそ
                return 'STAB ' . $monsters->aliveMonsters[rand(0, count($monsters->aliveMonsters) - 1)]->number;
            }
        }

        // TODO
        // var_dump($avg);

        $target = explode(',', $avg[0][0]);

        if (count($target) === 2) {
            return 'DOUBLE ' . $target[0] . ' ' . $target[1];
        } else if ($target[0] == -1){
            return 'SWING';
        } else {
            return 'STAB ' . $target[0];
        }
    }

    private function playout($player, $pattern, $score) {
        // TODO ターン数の調整
        if ($this->fast) {
            $loop = 30 + pow(count($this->monsters->targets), 3) / 2;
        } else {
            $loop = 200 + pow(count($this->monsters->targets), 3);
        }

        $heal_limit = 20;
        $min_heal = 100;
        $min_attack = 100;
        $min_record = [];
        $copy_monsters = new Monsters();

        for ($i = 0; $i < $loop; $i++) {
            $copy_player = clone $player;
            $copy_monsters->init($this->monsters->allMonsters);
            $copy_monsters->pickMonsters($player);
            $copy_monsters->pattern = $pattern;

            $heal = 0;
            $turn = 0;
            $all_record = [];
            $record = [];
            $time = $this->turn;
            $hasRecord = true;

            $flag = true;
            while ($flag) {
                for ($j = $time; $j < (int)($copy_player->agi / 15) + 1; $j++) {
                    if ($this->kaifuku($copy_player, $copy_monsters, $j)) {
                        $copy_player->hp = $copy_player->maxhp;
                        $copy_player->str = $copy_player->maxstr;
                        $copy_player->agi = $copy_player->maxagi;
                        $heal = $heal + 1;
                    } else if (count($copy_monsters->aliveMonsters) === 1) {
                        $copy_monsters->aliveMonsters[0]->hp -= random_int(1, $copy_player->str / 3 + 1);
                        $p = $copy_monsters->aliveMonsters[0]->number;
                    } else {
                        // ランダムで攻撃
                        $p = $copy_monsters->pattern[random_int(0, count($copy_monsters->pattern) - 1)];

                        if ($p[0] === -1) {
                            // SWINGのとき
                            $r = random_int(1, $copy_player->str / 3 + 1);
                            for ($k = 0; $k < $r; $k++) {
                                $rr = random_int(0, count($copy_monsters->aliveMonsters) - 1);
                                $copy_monsters->aliveMonsters[$rr]->hp -= 1;
                                if ($copy_monsters->aliveMonsters[$rr]->name === 'ヒドラ') {
                                    $copy_monsters->aliveMonsters[$rr]->level -= 1;
                                }
                                if ($copy_monsters->aliveMonsters[$rr]->hp === 0) {
                                    array_splice($copy_monsters->aliveMonsters, $rr, 1);
                                }
                                if (count($copy_monsters->aliveMonsters) === 0) {
                                    break;
                                }
                            }
                        } else if (count($p) > 1) {
                            // DOUBLEのとき
                            $d = random_int(1, max(1, $copy_player->str / 6));
                            for ($k = 0; $k < 2; $k++) {
                                if ($copy_monsters->keyMonsters[$p[$k]]->name === 'メタルヨテイチ') {
                                    $copy_monsters->keyMonsters[$p[$k]]->hp -= 1;
                                    continue;
                                }
                                if ($copy_monsters->keyMonsters[$p[$k]]->name === 'ヒドラ') {
                                    $copy_monsters->keyMonsters[$p[$k]]->level -= $d;
                                }
                                $copy_monsters->keyMonsters[$p[$k]]->hp -= $d;
                            }
                        } else if ($copy_monsters->keyMonsters[$p[0]]->name === 'メタルヨテイチ') {
                            $copy_monsters->keyMonsters[$p[0]]->hp -= 1;
                        } else {
                            $d = random_int(1, $copy_player->str / 2 + 2);
                            if ($copy_monsters->keyMonsters[$p[0]]->name === 'ヒドラ') {
                                $copy_monsters->keyMonsters[$p[0]]->level -= $d;
                            }
                            $copy_monsters->keyMonsters[$p[0]]->hp -= $d;
                        }

                        if ($hasRecord) {
                            $all_record[] = implode(',', $p);
                        }
                    }

                    // モンスターを更新
                    $copy_monsters->pickMonsters($copy_player);

                    // 攻撃パターンを再構成
                    $copy_monsters->pattern = $copy_monsters->createPattern();
                    if (count($copy_monsters->pattern) === 0) {
                        $hasRecord = false;
                        $copy_monsters->pattern = $copy_monsters->createAllPattern($copy_player);
                    }

                    if (count($copy_monsters->aliveMonsters) === 0) {
                        $flag = false;
                        break;
                    }
                    if ($heal === $heal_limit) {
                        $flag = false;
                        break;
                    }
                }

                if ($turn === 0) {
                    $record = $all_record;
                }

                $turn = $turn + 1;

                // 敵の攻撃
                $copy_monsters->enemyAttack($copy_player);

                // 臨終
                if ($copy_player->hp <= 0) {
                    break;
                }

                // 攻撃パターンを再構成
                $copy_monsters->pattern = $copy_monsters->createAllPattern($copy_player);

                $time = 0;
            }

            // 結果配列作成
            // [死亡フラグ、評価値、試行回数]
            foreach ($record as $r) {
                if ($copy_player->hp > 0) {
                    if (empty($score[$r])) {
                        $score[$r] = [
                            0,
                            $copy_player->hp * 1.2 + $copy_player->str + $copy_player->agi
                                - ($copy_player->maxhp * 1.2 + $copy_player->maxstr + $copy_player->maxagi) * $heal,
                            1
                        ];
                    } else {
                        $score[$r] = [
                            $score[$r][0],
                            $score[$r][1]
                                + $copy_player->hp * 1.2 + $copy_player->str + $copy_player->agi
                                - ($copy_player->maxhp * 1.2 + $copy_player->maxstr + $copy_player->maxagi) * $heal,
                            $score[$r][2] + 1
                        ];
                    }
                } else {
                    if (empty($score[$r])) {
                        $score[$r] = [1, -($copy_player->maxhp * 1.2 + $copy_player->maxstr + $copy_player->maxagi) * $heal_limit, 1];
                    } else {
                        $score[$r] = [
                            $score[$r][0] + 1,
                            $score[$r][1] - ($copy_player->maxhp * 1.2 + $copy_player->maxstr + $copy_player->maxagi) * $heal_limit,
                            $score[$r][2] + 1
                        ];
                    }
                }
            }
            unset($r);

            unset($copy_player);
        }

        unset($copy_monsters);

        return $score;
    }
}

class Monsters {
    const ORC_POWER = 1.6;
    const HYD_POWER = 0.6;
    const SLI_POWER = 1.2;
    const BRI_POWER = 1.2;
    const YOT_POWER = 0.5;

    public $allMonsters;
    public $aliveMonsters;
    public $targets;
    public $avgLevel;
    public $keyMonsters; // モンスター番号をキーにした配列
    public $pattern;

    public function createAllPattern($player, $all = false) {
        $pattern = [];

        // STABパターン
        foreach ($this->targets as $mon) {
            if ($mon->level < 50) {
                $pattern[] = [$mon->number];
            }
        }

        if ($all) {
            if (count($this->targets) > 1) {
                // DOUBLEパターン
                for ($i = 0; $i < count($this->targets) - 1; $i++) {
                    if ($this->targets[$i]->hp > $player->str * 0.3) {
                        continue;
                    }
                    for ($j = $i + 1; $j < count($this->targets); $j++) {
                        $pattern[] = [$this->targets[$i]->number, $this->targets[$j]->number];
                    }
                }

                // SWING
                $pattern[] = [-1];
            }
        }

        return $pattern;
    }

    public function createPattern() {
        $pattern = [];

        $swing = false;
        foreach ($this->pattern as $p) {
            if ($p[0] === -1 && count($this->keyMonsters) > 1) {
                $swing = true;
                continue;
            }

            if (count($p) === 1 && !empty($this->keyMonsters[$p[0]])) {
                // STABパターン
                $pattern[] = [$p[0]];
            } else if (!empty($this->keyMonsters[$p[0]]) && !empty($this->keyMonsters[$p[1]])) {
                // DOUBLEパターン
                $pattern[] = [$p[0], $p[1]];
            }
        }

        if ($swing && count($pattern) > 1) {
            $pattern[] = [-1];
        }

        return $pattern;
    }

    public function damage($player) {
        $player = clone $player;
        $aliveMonsters = unserialize(serialize($this->aliveMonsters));

        $hp_damage = 0;

        $high = 0.6 + 0.08 * max(0, (6 - count($this->targets)));
        $low = 0.6 + 0.08 * max(0, (6 - (count($aliveMonsters) - count($this->targets))));
        foreach($aliveMonsters as $mon) {
            if ($mon->level > $this->avgLevel) {
                $factor = $high;
            } else {
                $factor = $low;
            }

            switch($mon->name) {
                case "オーク":
                    $hp_damage +=  $mon->level * $factor + 1;
                    $player->hp -= $mon->level * $factor + 1;
                    break;
                case "ヒドラ":
                    $hp_damage +=  $mon->level / 2 * $factor + 1;
                    $player->hp -= $mon->level / 2 * $factor + 1;
                    break;
                case "スライム":
                    if ($player->agi > 0) {
                        $player->agi = max(0, $player->agi - $mon->level * $factor + 1);
                    } else {
                        $hp_damage +=  $mon->level * $factor + 1;
                        $player->hp -= $mon->level * $factor + 1;
                    }
                    break;
                case "ブリガンド":
                    if ($player->hp >= $player->str && $player->hp >= $player->agi) {
                        $hp_damage +=  $mon->level;
                        $player->hp -= $mon->level;
                    } else if ($player->agi >= $player->str && $player->agi > 0) {
                        $player->agi = max(0, $player->agi - $mon->level);
                    } else if ($player->str > 0) {
                        $player->str = max(0, $player->str - $mon->level);
                    } else {
                        $hp_damage +=  $mon->level;
                        $player->hp -= $mon->level;
                    }
                    break;
                case "メタルヨテイチ":
                    $hp_damage += $mon->level * $factor + 1;
                    $player->hp -= $mon->level * $factor + 1;
                    break;
                case "ハツネツエリア":
                    $hp_damage += ($player->level + 11)  * max(0.7, $factor);
                    $player->hp -= ($player->level + 11)  * max(0.7, $factor);
                    $player->str = max(0, $player->str - ($player->level + 11) * max(0.7, $factor));
                    break;
                case "もげぞう":
                    $hp_damage += ($player->level + 15)  * max(0.7, $factor);
                    $player->hp -= ($player->level + 15) * max(0.7, $factor);
                    $player->str = max(0, $player->str - ($player->level + 15) * max(0.7, $factor));
                    // $player->agi = max(0, $player->agi - ($player->level + 15) * max(0.7, $factor));
                    break;
            }
        }

        return $hp_damage;
    }

    public function init($allMonsters) {
        $this->allMonsters = unserialize(serialize($allMonsters));
    }

    public function pickMonsters($player) {
        $this->aliveMonsters = [];
        $this->targets = [];
        $this->keyMonsters = [];
        $this->avgLevel = 0;

        $avg_power = 0;

        // HP0以上のモンスターを抽出＆平均戦闘力を調べる
        foreach($this->allMonsters as $mon) {
            if ($mon->hp > 0) {
                array_unshift($this->aliveMonsters, $mon);

                if ($mon->level < /** BOSS LEVEL **/ 50) {
                    $this->keyMonsters[$mon->number] = $mon;

                    $this->avgLevel += $mon->level;

                    switch ($mon->name) {
                        case "オーク":
                            $avg_power += $mon->level * self::ORC_POWER;
                            break;
                        case "ヒドラ":
                            $avg_power += $mon->level * self::HYD_POWER;
                            break;
                        case "スライム":
                            $avg_power += $mon->level * self::SLI_POWER;
                            break;
                        case "ブリガンド":
                            $avg_power += $mon->level * self::BRI_POWER;
                            break;
                        case "メタルヨテイチ":
                            $avg_power += $mon->level * self::YOT_POWER;
                            break;
                    }
                }
            }
        }
        unset($mon);

        if (count($this->aliveMonsters) > 0) {
            $this->avgLevel = $this->avgLevel / count($this->aliveMonsters);
            $avg_power = $avg_power / count($this->aliveMonsters);
        }

        foreach($this->aliveMonsters as $mon) {
            switch ($mon->name) {
                case "オーク":
                    if ($mon->level * self::ORC_POWER > $avg_power - 0.0001) {
                        $this->targets[] = $mon;
                    }
                    break;
                case "ヒドラ":
                    if ($mon->level * self::HYD_POWER > $avg_power - 0.0001) {
                        $this->targets[] = $mon;
                    }
                    break;
                case "スライム":
                    if ($mon->level * self::SLI_POWER > $avg_power - 0.0001) {
                        $this->targets[] = $mon;
                    }
                    break;
                case "ブリガンド":
                    if ($mon->level * self::BRI_POWER > $avg_power - 0.0001) {
                        $this->targets[] = $mon;
                    }
                    break;
                case "メタルヨテイチ":
                    if ($mon->level * self::YOT_POWER > $avg_power - 0.0001) {
                        $this->targets[] = $mon;
                    }
                    break;
            }
        }
    }

    public function enemyAttack($player) {
        $aliveMonsters = &$this->aliveMonsters;

        foreach($aliveMonsters as $mon) {
            switch($mon->name) {
                case "オーク":
                    $player->hp -= random_int(1, $mon->level);
                    break;
                case "ヒドラ":
                    $player->hp -= random_int(1, max(1, $mon->level / 2));
                    $mon->level = $mon->level + 1;
                    $mon->hp = $mon->hp + 1;
                    break;
                case "スライム":
                    if ($player->agi > 0) {
                        $player->agi = max(0, $player->agi - random_int(1, $mon->level));
                    } else {
                        $player->hp -= random_int(1, $mon->level);
                    }
                    break;
                case "ブリガンド":
                    if ($player->hp >= $player->str && $player->hp >= $player->agi) {
                        $player->hp -= $mon->level;
                    } else if ($player->agi >= $player->str && $player->agi > 0) {
                        $player->agi = max(0, $player->agi - $mon->level);
                    } else if ($player->str > 0) {
                        $player->str = max(0, $player->str - $mon->level);
                    } else {
                        $player->hp -= $mon->level;
                    }
                    break;
                case "メタルヨテイチ":
                    if (random_int(0, 1)) {
                        $player->hp -= random_int(1, $mon->level);
                    }
                    break;
                case "ハツネツエリア":
                    switch(random_int(0, 2)) {
                        case 0:
                            $player->hp -= random_int(4, $player->level + 11);
                            break;
                        case 1:
                            $player->str = max(0, $player->str - random_int(4, $player->level + 11));
                            break;
                        case 2:
                            $mon->hp += random_int(4, $player->level + 11);
                            break;
                    }
                    break;
                case "もげぞう":
                    $r = random_int(0, 4);
                    if ($r < 2) {
                        $player->hp -= random_int(6, $player->level + 15);
                    } else if ($r < 3) {
                        $d = random_int(6, $player->level + 15);
                        $player->hp -= $d;
                        $player->str = max(0, $player->str - $d);
                        $player->agi = max(0, $player->agi - $d);
                    } else {
                        if ($player->agi > 0) {
                            $player->agi = max(0, $player->agi - random_int(6, $player->level + 15));
                        } else {
                            $player->hp -= random_int(6, $player->level + 15);
                        }
                    }
                    break;
            }
        }
    }
}

class MapMode {
    const WALL = 1;
    const BLOCK = 2;
    const ITEM = 3;
    const MBOSS = 4;
    const BOSS = 5;
    const EVENT = 6;
    const KAIDAN = 7;
    const BLANKMASU = 10; // 空白マスの初期状態
    const STEP = 11; // 経過マス

    public $shouldReload;
    public $masu;
    public $yoko;
    public $tate;
    public $player_pos;
    public $record;
    private $total_items;
    private $fast;

    public function __construct($fast = false) {
        $this->shouldReload = true;
        $this->fast = $fast;
    }

    public function move($player) {
        if ($this->kaifuku($player)) {
            return 'HEAL';
        }

        return $this->record[0];
    }

    public function update($act) {
        if ($act === 'HEAL') {
            return;
        }

        switch($act) {
            case 'UP':
                if ($this->masu[$this->player_pos - $this->yoko] === self::BLOCK) {
                    $this->masu[$this->player_pos - $this->yoko] = self::BLANKMASU;
                } else {
                    $this->player_pos = $this->player_pos - $this->yoko;
                }
                break;
            case 'LEFT':
                if ($this->masu[$this->player_pos - 1] === self::BLOCK) {
                    $this->masu[$this->player_pos - 1] = self::BLANKMASU;
                } else {
                    $this->player_pos = $this->player_pos - 1;
                }
                break;
            case 'RIGHT':
                if ($this->masu[$this->player_pos + 1] === self::BLOCK) {
                    $this->masu[$this->player_pos + 1] = self::BLANKMASU;
                } else {
                    $this->player_pos = $this->player_pos + 1;
                }
                break;
            case 'DOWN':
                if ($this->masu[$this->player_pos + $this->yoko] === self::BLOCK) {
                    $this->masu[$this->player_pos + $this->yoko] = self::BLANKMASU;
                } else {
                    $this->player_pos = $this->player_pos + $this->yoko;
                }
                break;
        }

        if ($this->masu[$this->player_pos] === self::ITEM
            || $this->masu[$this->player_pos] === self::MBOSS
            || $this->masu[$this->player_pos] === self::KAIDAN
        ) {
            $this->shouldReload = true;
        }

        // レコードを更新
        array_shift($this->record);
    }

    public function reload($json_obj) {
        // マップの大きさ
        $this->yoko = $json_obj->walls[0][0] + 1;
        $this->tate = $json_obj->walls[0][1] + 1;

        $this->player_pos = ($json_obj->player->pos->y * $this->yoko) + $json_obj->player->pos->x;

        $this->total_items = 0;
        $masu = [];
        // 空白マスで埋める
        for ($i = 0; $i < $this->yoko * $this->tate; $i++) {
            $masu[$i] = self::BLANKMASU;
        }

        foreach($json_obj->walls as $m) {
            $masu[($m[1] * $this->yoko) + $m[0]] = self::WALL;
        }
        foreach($json_obj->blocks as $m) {
            $masu[($m[1] * $this->yoko) + $m[0]] = self::BLOCK;
        }
        foreach($json_obj->items as $m) {
            $masu[($m[1] * $this->yoko) + $m[0]] = self::ITEM;
            $this->total_items = $this->total_items + 1;
        }
        foreach($json_obj->ha2 as $m) {
            $masu[($m[1] * $this->yoko) + $m[0]] = self::MBOSS;
        }
        foreach($json_obj->boss as $m) {
            $masu[($m[1] * $this->yoko) + $m[0]] = self::BOSS;
        }
        foreach($json_obj->events as $m) {
            $masu[($m[1] * $this->yoko) + $m[0]] = self::EVENT;
        }
        foreach($json_obj->kaidan as $m) {
            $masu[($m[1] * $this->yoko) + $m[0]] = self::KAIDAN;
        }

        $this->masu = $masu;

        // ルートを探索
        $this->record = $this->searchRoute($json_obj->player);
    }

    private function searchRoute($player) {
        $kaisu = $player->{'map-level'};
        // アイテムとハンマーの価値（ターン換算）
        if ($kaisu === 100) {
            $item_value = 0;
            $hammer_value = 0;
        } else if ($kaisu > 80) {
            $item_value = 14;
            $hammer_value = min(7, max(5, 14 - $player->hammer));
        } else if ($kaisu > 70) {
            $item_value = 16;
            $hammer_value = min(9, max(7, 18 - $player->hammer));
        } else if ($kaisu > 49) {
            $item_value = 20;
            $hammer_value = min(9, max(7, 23 - $player->hammer));
        } else if ($kaisu > 30) {
            $item_value = 24;
            $hammer_value = min(13, max(9, 31 - $player->hammer));
        } else if ($kaisu > 5) {
            $item_value = 26;
            $hammer_value = min(15, max(11, 25 - $player->hammer));
        } else {
            $item_value = 28;
            $hammer_value = min(9, max(7, 14 - $player->hammer));
        }
        if ($kaisu < 40 && $player->buki[1] + $player->buki[2] + $player->buki[3] < 15) {
            $hammer_value = 11;
        }
        // 緊急モード
        if ($player->heal < 3 && $kaisu < 50) {
            $item_value = 24;
            $hammer_value = 7;
        }
        // ブーストモード
        if ($player->maxstr > 50 && $player->maxagi > 55 && $player->heal > 21 && $kaisu > 49) {
            $item_value = 14;
            $hammer_value = 5;
        }

        $dire = array(
            'UP' => -$this->yoko,
            'LEFT' => -1,
            'RIGHT' => 1,
            'DOWN' => $this->yoko
        );

        // ['マス情報', 'プレイヤー位置(配列)', '使用ハンマー数', 'アイテム数', 'ターン数', '移動履歴配列']
        $queue[] = [$this->masu, $this->player_pos, 0, 0, 0, []];

        //結果の配列
        $result = [];

        // 探索ターンの上限
        if ($player->{'map-level'} === 50) {
            $turn_limit = 70;
        } else {
            $turn_limit = 50;
        }

        while (!empty($queue)) {
            $arr = array_shift($queue);

            $ms = $arr[0];
            $pp = $arr[1];
            $hammer = $arr[2];
            $item = $arr[3];
            $turn = $arr[4];
            $record = $arr[5];

            // 一定ターン以上過ぎたらスキップ
            if ($turn > $turn_limit) {
                continue;
            }

            // 主人公位置のマスを処理
            $ms[$pp] = self::STEP;

            // 周囲を調べる
            foreach ($dire as $dk => $dv) {
                // 階段・ボス
                if ($ms[$pp + $dv] === self::KAIDAN || $ms[$pp + $dv] === self::BOSS) {
                    $copy_re = $record;
                    $copy_re[] = $dk;

                    $result[] = ['record' => $copy_re, 'turn' => $turn + 1 + $hammer * $hammer_value - $item * $item_value];

                    unset($copy_re);
                    continue;
                }

                // 中ボス
                if ($player->heal > 8 && $ms[$pp + $dv] === self::MBOSS) {
                    $copy_re = $record;
                    $copy_re[] = $dk;

                    $result[] = ['record' => $copy_re, 'turn' => $turn + 1 + $hammer * $hammer_value - ($item + 100) * $item_value];

                    unset($r);
                    continue;
                }

                // アイテム
                if ($this->fast) {
                    $item_limit = 1;
                } else {
                    $item_limit = max(2, 6 - $this->total_items);
                    // $item_limit = 4;
                }
                if ($item <= $item_limit && $ms[$pp + $dv] === self::ITEM) {
                    $copy_re = $record;
                    $copy_re[] = $dk;

                    $copy_ms = $ms;
                    $copy_ms[$pp + $dv] = self::BLANKMASU;

                    // 足跡を消す
                    for ($i = 0; $i < count($copy_ms); $i++) {
                        if ($copy_ms[$i] === self::STEP) {
                            $copy_ms[$i] = self::BLANKMASU;
                        }
                    }

                    $queue[] = [$copy_ms, $pp + $dv, $hammer, $item + 1, $turn + 1, $copy_re];

                    unset($copy_re);
                    unset($copy_ms);
                    continue;
                }

                // 壊せる壁
                if ($hammer === 0 && $player->hammer > 0) {
                    if ($ms[$pp + $dv] === self::BLOCK) {
                        $find = false;
                        $block = false;
                        $n = 2;
                        // 主人公の軸上を調べる
                        while (true) {
                            if ($ms[$pp + $dv * $n] === self::WALL) {
                                break;
                            }
                            if ($ms[$pp + $dv * $n] === self::BLOCK) {
                                $block = true;
                                break;
                            }
                            if (!$block && $ms[$pp + $dv * $n] === self::ITEM) {
                                $find = true;
                                break;
                            }
                            if ($ms[$pp + $dv * $n] === self::MBOSS || $ms[$pp + $dv * $n] === self::KAIDAN) {
                                $find = true;
                                break;
                            }
                            $n = $n + 1;
                        }

                        if ($this->fast) {
                            $block = true;
                        }
                        if ($find || !$block) {
                            $copy_re = $record;

                            // 壊して移動
                            $copy_re[] = $dk;
                            $copy_re[] = $dk;
                            $copy_ms = $ms;
                            $copy_ms[$pp + $dv] = self::BLANKMASU;
                            $queue[] = [$copy_ms, $pp + $dv, $hammer + 1, $item, $turn + 1, $copy_re];

                            unset($copy_re);
                            unset($copy_ms);
                            unset($find);
                            unset($n);
                            continue;
                        }

                        unset($find);
                        unset($n);
                    }
                }

                // 空白マス
                if ($ms[$pp + $dv] === self::BLANKMASU) {
                    $copy_re = $record;

                    $copy_re[] = $dk;
                    $queue[] = [$ms, $pp + $dv, $hammer, $item, $turn + 1, $copy_re];

                    unset($copy_re);
                }
            }

            unset($arr);
            unset($ms);
            unset($pp);
            unset($break);
            unset($item);
            unset($hammer);
            unset($turn);
            unset($record);
        }
        unset($queue);

        $min_turn = 1000;
        foreach ($result as $r) {
            if ($r['turn'] < $min_turn) {
                $min_turn = $r['turn'];
                $min_record = $r['record'];
            }
        }

        return $min_record;
    }

    private function kaifuku($player) {
        if ($player->heal === 0) {
            return false;
        }

        if ($player->hp < $player->maxhp * 0.6 && $player->agi < $player->maxagi * 0.5) {
            return true;
        }

        if ($player->agi < $player->maxagi * 0.6 && $player->heal > 8) {
            return true;
        }

        if ($player->{'map-level'} === 100
            && ($player->hp < $player->maxhp * 0.6
               || $player->str < $player->maxstr * 0.7
               || $player->agi < $player->maxagi * 0.8
            )
        ) {
            return true;
        }

        if ($player->{'map-level'} === 50
            && ($player->hp < $player->maxhp * 0.6
               || $player->str < $player->maxstr * 0.7
               || $player->agi < 30
            )
        ) {
            return true;
        }

        return false;
    }
}
?>
