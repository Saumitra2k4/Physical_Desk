<?php
require_once __DIR__ . '/phase4.php';
function pd57_draft_create(array $values,array $suggestion,array $priority): string { $token=bin2hex(random_bytes(24)); $_SESSION['pd57_request_drafts'][$token]=['user_id'=>(int)Session::getLoginUserID(),'created'=>time(),'used'=>false,'values'=>$values,'suggestion'=>$suggestion,'priority'=>$priority]; return $token; }
function pd57_draft_get(string $token): ?array { $d=$_SESSION['pd57_request_drafts'][$token]??null; return (!$d||$d['used']||$d['user_id']!==(int)Session::getLoginUserID()||time()-$d['created']>900)?null:$d; }
function pd57_draft_consume(string $token): void { if(isset($_SESSION['pd57_request_drafts'][$token])) $_SESSION['pd57_request_drafts'][$token]['used']=true; }
