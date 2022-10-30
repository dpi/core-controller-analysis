<?php

declare(strict_types=1);

namespace Drupal\core_controller_analysis\Controller;

use dpi\DrupalPhpunitBootstrap\Utility;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

final class Analysis {

  use StringTranslationTrait;

  public function __invoke() {
    $build = [];
    (new CacheableMetadata())
      ->setCacheMaxAge(0)
      ->applyTo($build);

    $dirs = Utility::drupal_phpunit_contrib_extension_directory_roots(DRUPAL_ROOT);
    $dirs = array_filter($dirs, function (string $dir): bool {
      return str_contains($dir, 'core/');
    });

    $dirs = array_map([
      Utility::class,
      'drupal_phpunit_find_extension_directories',
    ], $dirs);

    $dirs = array_reduce($dirs, 'array_merge', []);

    $routes = [];

    $finder = new Finder();
    $result = $finder->files()->in($dirs)->name('*routing.yml');
    foreach ($result->files() as $file) {
      // Get the adjacent .info.yml file, determine if 'Testing'.
      $infoYmls = (new Finder())->in($file->getPath())->depth(0)->name('*.info.yml');
      $isTesting = NULL;
      foreach ($infoYmls->files() as $infoYml) {
        $yaml = Yaml::parse($infoYml->getContents());
        $isTesting = ($yaml['package'] ?? NULL) === 'Testing';
      }

      $yaml = Yaml::parse($file->getContents());
      foreach ($yaml as $item) {
        $controller = $item['defaults']['_controller'] ?? NULL;
        if ($controller !== NULL) {
          $routes[] = [$controller, $isTesting];
        }
      }
    }

    $controllers = [];
    foreach ($routes as [$controller, $isTesting]) {
      if (str_contains($controller, '::')) {
        [$class, $method] = explode('::', $controller);
      }
      else {
        $class = $controller;
        $method = NULL;
      }
      $controllers[$class]['class'] = $class;
      $controllers[$class]['isTesting'] = $isTesting;
      $controllers[$class]['methods'][] = $method;
    }

    uasort($controllers, function (array $itemA, array $itemB) {
      return strcasecmp($itemA['class'], $itemB['class']) <=> 0;
    });

    foreach ($controllers as &$controller) {
      $controller['methods'] = array_unique($controller['methods']);
    }

    uasort($controllers, function (array $itemA, array $itemB) {
      return count($itemA['methods']) <=> count($itemB['methods']);
    });

    $output = new BufferedOutput();
    $table1 = new Table($output);
    $table1->setHeaders(['Class', 'Method Count', 'Method Detail']);

    $table2 = new Table($output);
    $table2->setHeaders(['Class', 'Method Count', 'Method Detail']);
    foreach ($controllers as ['class' => $class, 'isTesting' => $isTesting, 'methods' => $methods]) {
      (!$isTesting ? $table1 : $table2)->addRow([
        $class,
        count($methods),
        implode(', ', $methods),
      ]);
    }
    $table1->render();
    $table2->render();
    $build['#markup'] = $output->fetch();

    return $build;
  }

}
