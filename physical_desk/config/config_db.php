<?php
class DB extends DBmysql {
   public $dbhost = 'db:3306';
   public $dbuser = 'glpi';
   public $dbpassword = 'glpi';
   public $dbdefault = 'pd57';
   public $use_timezones = true;
   public $use_utf8mb4 = true;
   public $allow_datetime = false;
   public $allow_signed_keys = false;
}
