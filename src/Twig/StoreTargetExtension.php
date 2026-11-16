<?php
declare(strict_types=1);
namespace App\Twig;
use App\Security\AccessTarget;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
final class StoreTargetExtension extends AbstractExtension
{
    public function getFunctions(): array {return [new TwigFunction('store_target',AccessTarget::store(...))];}
}
