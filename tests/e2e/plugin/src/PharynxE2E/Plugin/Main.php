<?php

declare(strict_types=1);

namespace PharynxE2E\Plugin;

use Brick\Math\BigInteger;
use dktapps\pmforms\MenuForm;
use Doctrine\Inflector\InflectorFactory;
use pocketmine\plugin\PluginBase;
use Webmozart\Assert\Assert;

final class Main {
    public function pluralize(string $word) : string {
        Assert::stringNotEmpty($word);
        return InflectorFactory::create()->build()->pluralize($word);
    }

    /**
     * @return array{string, string, string}
     */
    public function referencedClasses() : array {
        return [MenuForm::class, PluginBase::class, BigInteger::class];
    }
}
