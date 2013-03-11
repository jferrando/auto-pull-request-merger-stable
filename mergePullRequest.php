#!/usr/bin/env php
<?php
require_once 'Commands/Merge.php';
require_once 'Library/Git/Git.php';
require_once 'Library/hipchat-php/src/HipChat/HipChat.php';
require_once 'Library/GitHub-API-PHP/lib/autoloader.class.php';

$user = isset($argv[1]) ? $argv[1] : null;
$password = isset($argv[2]) ? $argv[2] : null;
$owner = isset($argv[3]) ? $argv[3] : null;
$repo = isset($argv[4]) ? $argv[4] : null;
$merge = new Merge();
$merge->pullRequest($user, $password, $owner, $repo);
