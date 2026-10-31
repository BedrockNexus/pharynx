<?php

declare(strict_types=1);

namespace Http;

use Acme\Util\Str;
use Acme\Virion\Form;

final class Client {
    public function send(object $thing, string $class) : Form {
        Str::of($class);
        return new Form();
    }
}
