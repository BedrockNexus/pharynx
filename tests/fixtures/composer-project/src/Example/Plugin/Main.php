<?php

declare(strict_types=1);

namespace Example\Plugin;

use Acme\Virion\Form;
use Http\Client;
use Server\Lib\Thing;

final class Main {
    public function run(Thing $thing) : Form {
        return (new Client())->send($thing, \Acme\Util\Str::class);
    }
}
