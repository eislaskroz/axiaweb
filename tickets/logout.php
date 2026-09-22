<?php
require __DIR__.'/lib/bootstrap.php';
if (!empty($_SESSION['access_token'])) supabase_request('POST','/auth/v1/logout',null,$_SESSION['access_token']);
$_SESSION=[]; if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);} session_destroy(); header('Location: index.php');
