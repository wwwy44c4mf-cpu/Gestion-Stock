<?php

declare(strict_types=1);
namespace App\Twig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\{AbstractExtension,GlobalsInterface};
/** A content version ensures browsers refresh the stylesheet after a deployment. */
final class AssetVersionExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir) {}
    public function getGlobals(): array
    {
        $hash=hash_file('sha256',$this->projectDir.'/public/assets/app.css');
        if($hash===false) {throw new \LogicException('Application stylesheet missing.');}
        return ['app_stylesheet_version'=>substr($hash,0,16)];
    }
}
