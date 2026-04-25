<?php

declare(strict_types=1);

namespace App\Models\Core;

use Zephyrus\Core\Config\ConfigSection;

/**
 * Custom configuration section for project-specific settings.
 *
 * Add this section to config.yml:
 *
 *   project:
 *     name: "My App"
 *     version: "1.0.0"
 *     maintenance: false
 *
 * Register it in Kernel::boot() via sectionFactories:
 *
 *   Configuration::fromYamlFile('config.yml', [
 *       'project' => ProjectConfig::class,
 *   ]);
 *
 * Access via:
 *
 *   $config->section('project')->getString('name');
 */
final class ProjectConfig extends ConfigSection
{
    public readonly string $name;
    public readonly string $version;
    public readonly bool $maintenance;

    public static function fromArray(array $values): static
    {
        $instance = new static($values);
        $instance->name = $instance->getString('name', 'Zephyrus App');
        $instance->version = $instance->getString('version', '1.0.0');
        $instance->maintenance = $instance->getBool('maintenance', false);

        return $instance;
    }
}
