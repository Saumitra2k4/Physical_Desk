<?php
include('../../../inc/includes.php');
require_once dirname(__DIR__) . '/inc/portal.php';
require_once dirname(__DIR__) . '/inc/operations.php';
Session::checkLoginUser();
$id=(int)($_POST['id']??0);$ticket=new Ticket();
if($_SERVER['REQUEST_METHOD']!=='POST'||!$ticket->getFromDB($id)||!(pd57_is_agent()||pd57_admin_is_authorized())||!$ticket->can($id,UPDATE)){http_response_code(403);exit('Not authorized.');}
$reason=(string)($_POST['reason_code']??'');if(!in_array($reason,['customer_impact','missed_commitment','additional_expertise_required','specialist_needed','manager_review'],true)){http_response_code(400);exit('Select an escalation reason.');}
$note=trim(strip_tags((string)($_POST['handoff_note']??'')));if(mb_strlen($note)<3){http_response_code(400);exit('Provide a brief handoff note.');}
if(!array_key_exists('target_designation_level',$_POST)||$_POST['target_designation_level']===''){http_response_code(400);exit('Select a higher professional designation.');}
if (!isset($_POST['expected_escalation_count']) || !ctype_digit((string)$_POST['expected_escalation_count'])) { http_response_code(409); exit('Refresh the request before escalating.'); }
try{pd57_ops_escalate($id,'manual_escalation',$reason,mb_substr($note,0,1500),(int)Session::getLoginUserID(),null,(int)$_POST['target_designation_level'],(int)$_POST['expected_escalation_count']);Html::redirect('/plugins/pd57portal/front/ticket.php?id='.$id.'&escalated=1');}catch(Throwable $e){http_response_code(400);exit($e->getMessage());}
