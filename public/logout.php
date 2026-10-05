<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/auth.php';

auth_logout();
redirect('login.php');
