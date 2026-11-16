<?php

namespace App\Commands;

use CodeIgniter\CLI\{BaseCommand,CLI};

final class RunReports extends BaseCommand
{
    protected $group = 'Cement';
    protected $name = 'cement:reports';
    protected $description = 'Generate due report snapshots and process configured email delivery.';
    public function run(array $params)
    {
        $store = new \App\Libraries\Cement\Store();
        $store->migrate();
        foreach ((new \App\Libraries\Cement\Scheduler($store))->run() as $outcome) {
            CLI::write(json_encode($outcome));
        }
    }
}
