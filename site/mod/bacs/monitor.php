﻿<?php

/**
 *
 * @package    mod
 * @subpackage bacs
 */

//Проверить все isset!!!

// HEADER START BOOTSTRAP

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/lib.php');

$id = optional_param('id', 0, PARAM_INT); // course_module ID, or
$b  = optional_param('b', 0, PARAM_INT);  // bacs instance ID - it should be named as the first character of the module
$edit  = optional_param('edit', 0, PARAM_BOOL); // Edit contest mode


if ($id) {
    $cm         = get_coursemodule_from_id('bacs', $id, 0, false, MUST_EXIST);
    $course     = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $bacs  = $DB->get_record('bacs', array('id' => $cm->instance), '*', MUST_EXIST);
} elseif ($b) {
    $bacs  = $DB->get_record('bacs', array('id' => $b), '*', MUST_EXIST);
    $course     = $DB->get_record('course', array('id' => $bacs->course), '*', MUST_EXIST);
    $cm         = get_coursemodule_from_instance('bacs', $bacs->id, $course->id, false, MUST_EXIST);
} else {
    error('You must specify a course_module ID or an instance ID');
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);

//add_to_log($course->id, 'bacs', 'view', "monitor.php?id={$cm->id}", $bacs->name, $cm->id);

/// Print the page header

$PAGE->set_url('/mod/bacs/monitor.php', array('id' => $cm->id));
$PAGE->set_title(format_string($bacs->name));

$PAGE->requires->css('/mod/bacs/bootstrap/css/bootstrap.min.css');

$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
// Output starts here
echo $OUTPUT->header();
echo $OUTPUT->heading($bacs->name);
$PAGE->navbar->ignore_active();
$PAGE->navbar->add('preview', new moodle_url('/a/link/if/you/want/one.php'));
$PAGE->navbar->add('name of thing', new moodle_url('/a/link/if/you/want/one.php'));

// HEADER END BOOTSTRAP

//СПОСОК КОНТЕСТОВ
//$fields='*'
//$results = $DB->get_records('bacs',array('upsolving' => $bacs->id));
$results = $DB->get_records('bacs', array('upsolving' => $bacs->id));
$table = new html_table();
$table->head = array('Contest', 'Starttime', 'Stoptime');
foreach($results as $result) {
	$table->data[] = array(format_string($result->name),format_string($result->starttime),format_string($result->endtime));
};
echo html_writer::table($table);

//ИЩЕМ КОНТЕСТ
$now = time();
//if (!((int)$_GET['c_id']))
	$id = (int)$bacs->id;
//else
//	$id = (int)$_GET['c_id'];
	
$result = $DB->get_record_select('bacs', "starttime < $now AND id = $id", array($params=null),'id, name');
//!!!Костыль: проверить на корректость проверки объекта
if (!is_object($result)) {
    if ($DB->count_records('bacs', array('id' => $id)) == 0)
        die('Not found!/Контест не найден');
    else
        die('Contest has not started/Контест ещё не начался.');
}
$cid = $result->id;
$cname = $result->name;

//ПОЛЬЗОВАТЕЛЬ
//(int)$_GET['user_id'];
//echo $USER->id;

//СТАТУС КОНТЕСТА
$starttime = $bacs->starttime;
$endtime = $bacs->endtime;
$runtime = (time() - $starttime)/60;
$tottime = ($endtime - $starttime)/60;
$status = 0;
//if ($freeze && ($runtime > $freeze) && (!$unfreeze || ($runtime < $unfreeze) )) $status = 1;
if ($status == 0 && $runtime < 0) $status = -1;
if ($status == 0 && $runtime > $tottime) $status = 2;
if ($runtime < 0) $runtime = 0;
if ($runtime > $tottime) $runtime = $tottime;
$runtime = (int)$runtime;
$tottime = (int)$tottime;
switch ($status) {
    case -1: $statustext = "Not started"; break;
    case 0: $statustext = "Running"; break;
    case 1: $statustext = "Frozen"; break;
    case 2: $statustext = "Over"; break;
}
echo "Time: <b>$runtime</b> of <b>$tottime</b>. Status: <b>$statustext</b>.<br>";

//ПРОВЕРКА НА АДМИНА

//ПРОВЕРКА ПРАВ НА ПРОСМОТР КОНТЕСТА

//СТАТИСТИКА
//SELECT MIN(`submit_time`), `user_id`, `task_id` FROM `mdl_bacs_submits` WHERE `contest_id` = 2 GROUP BY `task_id` HAVING MIN(`submit_time`)

//ЗАГОЛОВОК
$results = $DB->get_records('bacs_tasks_to_contests',array('contest_id' => $bacs->id),'task_order');
$table = new html_table();
$header = array();
$header[] = 'N';
$header[] = 'User name';
foreach($results as $result) {
    //!!!Костыль: нет проверки переполнения
    if  ($result->task_order < 26) {
        $header[] = format_string(chr(65 + $result->task_order));
    }
    else {
        $header[] = format_string(chr(65 + ($result->task_order % 10)) + (int)($result->task_order/10));
    }
    //Собираем литеры контеста
    $lid[$result->task_id . " " . $result->contest_id] = $result->task_order;
};
$header[] = '+';
$header[] = 'Time';
$table->head = $header;

//МОНИТОР
class MyRec {
    var $fault;
    var $ac;
    var $ac_time;
    var $submit_time;
}
class Boo {
    var $id;
    var $name;
    var $pen;
    var $solved;
    var $start_time;
}
function user_cmp($a, $b) {
    if ($a->solved > $b->solved) return -1;
    else if ($a->solved < $b->solved) return 1;
    else if ($a->pen < $b->pen) return -1;
    else if ($a->pen > $b->pen) return 1;
    else if ($a->name < $b->name) return -1;
    else if ($a->name > $b->name) return 1;
    else return 0;
}
function formtime($sec)
{
    $hr = (int)($sec / 3600);
    $min = (int)($sec / 60) % 60;
    if ($min < 10)
            $min = '0'.$min;

    return ($hr.':'.$min);
}
//Заметка, отрицательные отправки по контесту не проверили
$results = $DB->get_records_select('bacs_submits', "submit_time <= $endtime AND contest_id = $id", array($params=null),'id, user_id, contest_id, task_id, lang_id, source, submit_time, result_id, test_num_failed, max_time_used, max_memory_used, info');
//Ищем сколько фэйлов сделал пользователь и когда произошел аксепт по каждой задачке
unset($data);
foreach($results as $result) {
    $cur_uid = format_string($result->user_id); //Получаем id пользователя
    $lit = $lid[$result->task_id." ".$result->contest_id]; //Литера
    $rec = $data[$cur_uid][$lit]; //Собираем статистику по связке пользователь+[задача+контест]
    if (!$rec) { //Если первый проход
        $rec = new MyRec();
        $rec->fault = 0; //Ошибок
        $rec->ac = 0; //Правильных
        $rec->ac_time = 0; //Время правильного
    }
    if ($rec->ac)//Если правильно решена выходим
        continue;
    if (format_string($result->result_id) == 0) { //Сохраняем данные по принятой задачке
        $rec->ac = 1;
        $rec->ac_time = $result->submit_time - $bacs->starttime;
    }
    else {
        ++$rec->fault; //Считаем кол-во фолов
        $rec->submit_time = $result->submit_time - $bacs->starttime;
    }
    $data[$cur_uid][$lit] = $rec; //Возвращаем полученные данные
}

//Подсчитываем результат для каждого
$usern = 0;
$cstat = array(array());
if (isset($data)) {
    foreach ($data as $cur_uid => $rec0) {
        $u = new Boo();
        $u->id = $cur_uid;
        $hh[$cur_uid] = $usern;
        $u->pen = 0;
        $u->solved = 0;
        foreach ($rec0 as $lit => $rec) {
            if ($rec->ac) {
                ++$u->solved;
                $u->pen += (int)($rec->ac_time / 60);
                $u->pen += $rec->fault * 20;
                $cstat[0][$lit] = ++$сstat[0][$lit];
            } else {
                $cstat[1][$lit] = $сstat[1][$lit] + $rec->fault;
            }
        }
        $user[$usern] = $u;
        ++$usern;
    }
}

if (isset($user)) {
    $list = array();
    foreach ($user as $i => $u) {
        if ((int)$u->id == 0)
            continue;
        $list[] = $u->id;
    }
    $results = $DB->get_records_list('user', 'id', $list, null, 'id, firstname, lastname');
    foreach($results as $result) {
        $user[$hh[$result->id]]->name = format_string($result->firstname).' '.format_string($result->lastname);
        //$user[$hh[$row[0]]]->start_time = $row[2];
    };
}

if ($usern) usort($user, "user_cmp");
$now = time();
$table->data = array();
$table->size  = array('32px', '', '32px','32px','32px','32px','64px');
$aligns = array();
//$result = array();
$aligns[] = "center";
$aligns[] = "left";
for ($i = 0; $i < $usern; ++$i) {
    $place[$i] = $i + 1;
    if ($i && ($user[$i - 1]->solved == $user[$i]->solved) && ($user[$i - 1]->pen == $user[$i]->pen)) $place[$i] = $place[$i - 1];
/**/
    $result = array($place[$i], $user[$i]->name);
    $cells = array();
    $cells[] = $place[$i];
    $cells[] = $user[$i]->name;
    $uid = $user[$i]->id;
    foreach ($lid as $tmp => $lit) {
        $rec = $data[$uid][$lit];
        if (!$rec) $msg = '&nbsp;';
        else {
            if ($rec->ac) {
               $msg = '<font color=green>+';
               if ($rec->fault) $msg .= $rec->fault;
               $time = formtime($rec->ac_time);
               $msg .= "<br><font size=-2>$time</font></font>";
            }
            else {
                if ($rec->fault) {
                    $time = formtime($rec->submit_time);
                    $msg = "<font color=red>-$rec->fault";
                    $msg .= "<br><font size=-2>$time</font></font>";
                }
                else $msg = '&nbsp;';
            }
        }
        //$cell = new html_table_cell();
        //$msg->attributes['class'] = 'rrr';
        //$msg->text = 'rrr';
        $cells[] = $msg;
        $aligns[] = "center";

    }
    $cells[] = $user[$i]->solved;
    $cells[] = $user[$i]->pen;
    //$table->align = array('center','center','center','center','center');
    //$table->attributes['style']->cells[1] = 'color:red';
    $table->align = $aligns;
    $table->data[] = $cells;
}
$aligns[] = "center";
$aligns[] = "center";
$table->data[] = array('','<font color=green>Зачтено:</font>',$cstat[0][1],$cstat[0][2],$cstat[0][3],'','');
$table->data[] = array('','<font color=red>Попыток:</font>',$cstat[1][1],$cstat[1][2],$cstat[1][3],'','</font>');
$table->data[] = array('','Всего посылок:',$cstat[0][1]+$cstat[1][1],$cstat[0][2]+$cstat[1][2],$cstat[0][3]+$cstat[1][3],'','');
//Печатаем табличку
echo html_writer::table($table);

///---
$results = $DB->get_records_select('bacs_langs','');

$LANGUAGES = array('C++'=>'+', 'PASCAL'=>'P', 'C'=>'C', 'Java'=>'J', 'Python 3'=>'T');
//function bacs_print_task_send_form($bacs,$taskId){
    global $LANGUAGES;
    $task_select='';
    $lang_select='';
    foreach ($LANGUAGES as $lang=>$slang){
        $lang_select.="<option value='$slang'>$lang\n";
    }
    //foreach($results as $result) {
    //	$lang_select.="<option value='format_string($result->langs_id)'>format_string($result->$str)\n";
    //}
    //$task_ids = $DB->get_records("bacs_m2m", "contest_id", $bacs->contest_id,'task_order,task_id');
    $task_ids = $DB->get_records('bacs_tasks_to_contests', array('contest_id' => $bacs->id), 'task_order, task_id');
    $aid='A';
    foreach ($task_ids as $task_id){
        $task = $DB->get_record('bacs_tasks',array('task_id' => task_id));
        if ($task->task_id==$taskId)
        $sel='selected';
        else
        $sel='';
        $task_select.="<option value='$task->task_id' $sel>$aid. $task->name\n";
        $aid=chr(ord($aid)+1);
    }

    $task_answer='';
    if ($_GET["answer"]!="")
    $task_answer=stripslashes(stripslashes($_GET["answer"]));
    print '<form enctype="multipart/form-data" method="POST">
    <table class="answer_table">
    <tr>
    <td width="50%">task:<BR><select name="task_id" size=1 style="width:100%">'.$task_select.'</select></td>
    <td width="50%">prog lang:<BR><select name="lang" size=1 style="width:100%">'.$lang_select.'</select></td>
    </td>
    <tr>
    <td colspan=2>source:<BR><textarea name="source" style="width:100%" rows=20>'.$task_answer.'</textarea></td>
    </tr>
    <tr>

    </tr>
    <tr>
    <td colspan=2 align="center"><input type=submit value="send answer"></td>
    </tr>
    </table>
    </form>';
//}
//<td colspan=2>load from file:<BR><input type=file name="sourcefile" style="width:100%"></td>
//bacs_print_task_send_form($bacs,2);

echo $OUTPUT->footer();
