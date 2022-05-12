<?php

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */

use Robo\Tasks;

/**
 * Robo Tasks.
 */
class RoboFile extends Tasks {

  /**
   * The path to custom modules.
   *
   * @var string
   */
  const CUSTOM_MODULES = __DIR__ . '/web/modules/custom';

  /**
   * The path to contrib modules.
   *
   * @var string
   */
  const CONTRIB_MODULES = __DIR__ . '/web/modules/contrib';

  /**
   * The path to custom themes.
   *
   * @var string
   */
  const CUSTOM_THEMES = __DIR__ . '/web/themes/custom';

  /**
   * The path to contrib themes.
   *
   * @var string
   */
  const CONTRIB_THEMES = __DIR__ . '/web/themes/contrib';

  /**
   * New Project init.
   * TODO update
   */
  public function projectInit() {
    $LOCAL_MYSQL_USER = getenv('DRUPAL_DB_USER');
    $LOCAL_MYSQL_PASSWORD = getenv('DRUPAL_DB_PASS');
    $LOCAL_MYSQL_DATABASE = getenv('DRUPAL_DB_NAME');
    $LOCAL_MYSQL_PORT = getenv('DRUPAL_DB_PORT');
    $LOCAL_CONFIG_DIR = getenv('DRUPAL_CONFIG_DIR');

    $this->say("Initializing new project...");
    $collection = $this->collectionBuilder();
    $collection->taskComposerInstall()
      ->ignorePlatformRequirements()
      ->noInteraction()
      ->taskExec("drush si --account-name=admin --account-pass=admin --config-dir=$LOCAL_CONFIG_DIR --db-url=mysql://$LOCAL_MYSQL_USER:$LOCAL_MYSQL_PASSWORD@database:$LOCAL_MYSQL_PORT/$LOCAL_MYSQL_DATABASE standard -y")
      ->taskExec("drush pm:enable search -y")
      ->taskExec("drush theme:enable lark -y")
      ->taskExec("drush config-set system.theme admin lark -y")
      ->taskExec('drush cr')
      ->taskExec($this->fixPerms());
    $this->say("New project initialized.");

    return $collection;
  }

  /**
   * Local Site update.
   * TODO Update
   */
  public function localUpdate() {
    $this->say("Local site update starting...");
    $collection = $this->collectionBuilder();

    $collection->taskComposerInstall()
      ->taskExec('drush state:set system.maintenance_mode 1 -y')
      ->taskExec('drush updatedb --no-cache-clear -y')
      ->taskExec('drush cim -y || drush cim -y')
      ->taskExec('drush cim -y')
      ->taskExec('drush php-eval "node_access_rebuild();" -y')
      ->addTask($this->buildTheme())
      ->taskExec('drush cr')
      ->taskExec('drush state:set system.maintenance_mode 0 -y')
      ->taskExec('drush cr');
    $this->say("local site Update Completed.");
    return $collection;
  }

  /**
   * Build theme.
   *
   * @param string $dir
   *  The directory to run the commands.
   *
   * @return \Robo\Collection\CollectionBuilder
   */
  public function buildTheme($dir = '') {
    if (empty($dir)) {
      echo 'Must pass in a theme' . PHP_EOL;
    } else {
      $collection = $this->collectionBuilder();
      $collection->progressMessage('Building the theme...')
        ->taskNpmInstall()->dir($dir)
        ->taskExec('cd ' . $dir . ' && npm rebuild node-sass && npm run build');
      return $collection;
    }
  }

  /**
   * Watch theme.
   */
  public function watchTheme($theme) {
    if (!empty($theme)) {
      $this->taskGulpRun('buildForWatch')
        ->dir(self::CUSTOM_THEMES . '/' . $theme)
        ->run();
      $this->taskGulpRun('watch')
        ->dir(self::CUSTOM_THEMES . '/' . $theme)
        ->run();
    }
    echo 'Must pass in a theme' . PHP_EOL;
  }

  /**
   * Lint.
   */
  public function lint() {
    $this->say("parallel-lint checking custom modules and themes...");
    $this->taskExec('vendor/bin/parallel-lint -e php,module,inc,install,test,profile,theme')
      ->arg(self::CUSTOM_MODULES)
      ->arg(self::CUSTOM_THEMES)
      ->printOutput(TRUE)
      ->run();
    $this->say("parallel-lint finished.");
    $this->taskGulpRun('lint')->dir(self::CUSTOM_THEMES . '/prac')->run();
  }

  /**
   * Runs Codesniffer.
   */
  public function phpcs($module = '', $type = '') {
    $this->say("php code sniffer (drupalStandards) started...");
    $task = $this->taskExec('vendor/bin/phpcs -s');
    // Default settings if no project or developer settings are found
    $task->arg('--standard=Drupal,DrupalPractice')
      ->arg('--extensions=php,module,inc,install,test,profile,theme,info')
      ->arg('--ignore=*/node_modules/*,*/vendor/*');
    if ($type === 'theme') {
      $result = $task->arg(self::CONTRIB_THEMES . '/' . $module)
        ->printOutput(TRUE)
        ->run();
    }
    else {
      $result = $task->arg(self::CONTRIB_MODULES . '/' . $module)
        ->printOutput(TRUE)
        ->run();
    }
    $message = $result->wasSuccessful() ? 'No Drupal standards violations found :)' : 'Drupal standards violations found :( Please review the code.';
    $this->say("php code sniffer finished: " . $message);
  }

  /**
   * Runs Beautifier.
   */
  public function codefix($module = '', $type = '') {
    $this->say("php code beautifier (drupalStandards) started...");
    $task = $this->taskExec('vendor/bin/phpcbf -n');
    // Default settings if no project or developer settings are found
    $task->arg('--standard=Drupal,DrupalPractice')
      ->arg('--extensions=php,module,inc,install,test,profile,theme,info')
      ->arg('--ignore=*/node_modules/*,*/vendor/*');
    if ($type === 'theme') {
      $task->arg(self::CONTRIB_THEMES . '/' . $module)
        ->printOutput(TRUE)
        ->run();
    }
    else {
      $task->arg(self::CONTRIB_MODULES . '/' . $module)
        ->printOutput(TRUE)
        ->run();
    }
    $this->say("php code beautifier finished.");
  }

  /**
   * Fixes files permissions.
   *
   * @return \Robo\Collection\CollectionBuilder|\Robo\Task\Base\ExecStack
   *   Exec chown and chmod.
   */
  public function fixPerms() {
    return $this->taskExecStack()
      ->stopOnFail()
      ->exec('chown $(id -u) ./')
      ->exec('chmod u=rwx,g=rwxs,o=rx ./')
      ->exec('find ./ -not -path "web/sites/default/files*" -exec chown $(id -u) {} \;')
      ->exec('find ./ -not -path "web/sites/default/files*" -exec chmod u=rwX,g=rwX,o=rX {} \;')
      ->exec('find ./ -type d -not -path "web/sites/default/files*" -exec chmod g+s {} \;')
      ->exec('chmod -R u=rwx,g=rwxs,o=rwx ./web/sites/default/files');
  }

  /**
   * Set/Unset maintenance mode.
   *
   * @param int $status
   *
   * @return \Robo\Collection\CollectionBuilder|\Robo\Task\Base\ExecStack
   */
  public function maintenanceMode(int $status) {
    return $this->taskExecStack()
      ->stopOnFail()
      ->exec("drush state:set system.maintenance_mode $status")
      ->exec("drush cr");
  }

}
