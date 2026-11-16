<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateCement extends Migration
{
    public function up()
    {
        (new \App\Libraries\Cement\Store())->migrate();
    }
    public function down()
    {
        throw new \RuntimeException('Use a verified database backup; destructive automatic rollback is disabled.');
    }
}
