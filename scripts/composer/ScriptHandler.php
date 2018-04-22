<?php

/**
 * @file
 * Contains \Drupal\composer\ScriptHandler.
 */

namespace Drupal\composer;

use Composer\Script\Event;
use Composer\Semver\Comparator;
use Composer\Util\ProcessExecutor;
use DrupalFinder\DrupalFinder;
use Drupal\Component\Utility\Crypt;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class ScriptHandler {

  /**
   * Parse the config file and return settings.
   *
   * @param boolean $var
   * @return mixed
   *    The array of settings OR the requested setting value.
   */
  protected static function loadSettings() {
    // Load .env file if exists
    $projectRoot = self::getComposerRoot();
    if (file_exists($projectRoot . '/.env')) {
      $dotenv = new \Dotenv\Dotenv($projectRoot);
      $dotenv->load();
    }
  }

  protected static function getDrupalRoot() {
    $drupalFinder = new DrupalFinder();
    $drupalFinder->locateRoot(getcwd());
    $drupalRoot = $drupalFinder->getDrupalRoot();    
    return $drupalRoot;
  }
  
  protected static function getComposerRoot() {
    $drupalFinder = new DrupalFinder();
    $drupalFinder->locateRoot(getcwd());
    $composerRoot = $drupalFinder->getComposerRoot();
    return $composerRoot;
  }

  protected static function getCertificatesRoot() {
    return static::getComposerRoot() . '/certificates';
  }

  /**
   * Get path to Drush bin.
   *
   * @return string
   *    The absolute path to Drush bin file.
   */
  protected static function getDrush() {
    return static::getComposerRoot() . '/bin/drush';
  }

  /**
   * Get the list of folders with custom code.
   * @return array
   */
  public static function getCustomFolders() {
    $drupalRoot = self::getDrupalRoot();
    return [
      // $drupalRoot . '/profiles/custom/',
      $drupalRoot . '/modules/custom/',
      $drupalRoot . '/themes/custom/',
    ];
  }

  /**
   * Checks if the installed version of Composer is compatible.
   *
   * Composer 1.0.0 and higher consider a `composer install` without having a
   * lock file present as equal to `composer update`. We do not ship with a lock
   * file to avoid merge conflicts downstream, meaning that if a project is
   * installed with an older version of Composer the scaffolding of Drupal will
   * not be triggered. We check this here instead of in drupal-scaffold to be
   * able to give immediate feedback to the end user, rather than failing the
   * installation after going through the lengthy process of compiling and
   * downloading the Composer dependencies.
   *
   * @see https://github.com/composer/composer/pull/5035
   */
  public static function checkComposerVersion(Event $event) {
    $composer = $event->getComposer();
    $io = $event->getIO();

    $version = $composer::VERSION;

    // The dev-channel of composer uses the git revision as version number,
    // try to the branch alias instead.
    if (preg_match('/^[0-9a-f]{40}$/i', $version)) {
      $version = $composer::BRANCH_ALIAS_VERSION;
    }

    // If Composer is installed through git we have no easy way to determine if
    // it is new enough, just display a warning.
    if ($version === '@package_version@' || $version === '@package_branch_alias_version@') {
      $io->writeError('<warning>You are running a development version of Composer. If you experience problems, please update Composer to the latest stable version.</warning>');
    }
    elseif (Comparator::lessThan($version, '1.0.0')) {
      $io->writeError('<error>Drupal-project requires Composer version 1.0.0 or higher. Please update your Composer before continuing</error>.');
      exit(1);
    }
  }

  public static function buildScaffold(Event $event) {
    $fs = new Filesystem();
    if (!$fs->exists(static::getDrupalRoot() . '/autoload.php')) {
      \DrupalComposer\DrupalScaffold\Plugin::scaffold($event);
    }
  }

  public static function createRequiredFiles(Event $event) {
    self::loadSettings();
    $fs = new Filesystem();
    $drupalRoot = static::getDrupalRoot();
    $composerRoot = static::getComposerRoot();

    $dirs = [
      'modules',
      'profiles',
      'themes',
    ];

    // Required for unit testing
    foreach ($dirs as $dir) {
      if (!$fs->exists($drupalRoot . '/'. $dir)) {
        $fs->mkdir($drupalRoot . '/'. $dir);
        $fs->touch($drupalRoot . '/'. $dir . '/.gitkeep');
      }
    }

    // Prepare the settings file for installation
    if (!$fs->exists($drupalRoot . '/sites/default/settings.php')) {
      $fs->chmod($drupalRoot . '/sites/default/', 0755);
      $fs->copy($drupalRoot . '/sites/default/default.settings.php', $drupalRoot . '/sites/default/settings.php');
      $fs->chmod($drupalRoot . '/sites/default/settings.php', 0666);
      $event->getIO()->write("Created a sites/default/settings.php file with chmod 0666");
    }

    // Prepare the services file for installation
    if (!$fs->exists($drupalRoot . '/sites/default/services.yml')) {
      $fs->chmod($drupalRoot . '/sites/default/', 0755);
      $fs->copy($drupalRoot . '/sites/default/default.services.yml', $drupalRoot . '/sites/default/services.yml');
      $fs->chmod($drupalRoot . '/sites/default/services.yml', 0666);
      $event->getIO()->write("Created a sites/default/services.yml file with chmod 0666");
    }

    // Create the files directory with chmod 0777
    if (!$fs->exists($drupalRoot . '/sites/default/files')) {
      $oldmask = umask(0);
      $fs->mkdir($drupalRoot . '/sites/default/files', 0777);
      umask($oldmask);
      $event->getIO()->write("Created a sites/default/files directory with chmod 0777");
    }

    // Prepare the local settings file for installation with chmod 666.
    if (!$fs->exists($drupalRoot . '/sites/default/settings.local.php') and $fs->exists($drupalRoot . '/sites/default/example.settings.local.php')) {
      $fs->copy($drupalRoot . '/sites/default/example.settings.local.php', $drupalRoot . '/sites/default/settings.local.php');
      $fs->chmod($drupalRoot . '/sites/default/settings.local.php', 0666);
      $event->getIO()->write("Created a sites/default/settings.local.php file with chmod 0666");
    }

    // Prepare the local services file for development.
    if (!$fs->exists($drupalRoot . '/sites/default/services.development.yml') and $fs->exists($drupalRoot . '/sites/default/example.services.development.yml')) {
      $fs->copy($drupalRoot . '/sites/default/example.services.development.yml', $drupalRoot . '/sites/default/services.development.yml');
      $fs->chmod($drupalRoot . '/sites/default/services.development.yml', 0666);
      $event->getIO()->write("Created a sites/default/settings.local.php file with chmod 0666");
    }

    // Create the files directory with chmod 0666
    if (!$fs->exists($composerRoot . '/.env') and $fs->exists($composerRoot . '/example.env')) {
      $fs->copy($composerRoot . 'example.env', $composerRoot . '.env');
      $fs->chmod($composerRoot . '.env', 0666);
      $event->getIO()->write("Created .env file with chmod 0666");
    }
  }

  /**
   * Delete git from contrib and vendor folders.
   *
   * @return void
   */
  public static function gitCleanup(Event $event) {
    $io = $event->getIo();
    $drupalRoot = static::getDrupalRoot();   
    $process = new ProcessExecutor($io);
    $directories = [
      $drupalRoot,
      'vendor/',
      'config/'
    ];
    foreach ($directories as $dir) {
      $process->execute('find ' . $dir . ' -type d -name ".git" | xargs rm -rf');
      $io->write("Done! Git submodules removed from " . $dir);
    }  
  }

  /**
   * Delete node_modules or bower_modules folders.
   *
   * @return void
   */
  public static function npmCleanup(Event $event) {
    $io = $event->getIo();
    $drupalRoot = static::getDrupalRoot();   
    $process = new ProcessExecutor($io);
    $directories = self::getCustomFolders();
    $io->write("Deleting node_modules and bower_modules folders...");
    foreach ($directories as $dir) {
      $process->execute('find ' . $dir . ' -type d -name "node_modules" | xargs rm -rf');
      $process->execute('find ' . $dir . ' -type d -name "bower_modules" | xargs rm -rf');
      $io->write("Done! Garbage folders removed from " . $dir);
    }  
  }  

  public static function dependencyCleanup(Event $event) {
    $fs = new Filesystem();
    $io = $event->getIO();
    $root = self::getComposerRoot();
    $drupalRoot = static::getDrupalRoot();  

    $directories = array(
      "bin",
      "vendor",
      "drush/contrib",
      $drupalRoot . "/core",
      $drupalRoot . "/libraries",
      $drupalRoot . "/modules/contrib",
      $drupalRoot . "/profiles/contrib",
      $drupalRoot . "/themes/contrib",
    );

    $io->write("Removing directories (bin, vendor, core, libraries and contrib)."); 
    $fs->remove($directories);

    if ($fs->exists($root . '/composer.lock')) {
      $event->getIo()->write("Removing composer.lock file");
      $fs->remove($root . '/composer.lock');
    }

    $io->write("Everything's clean!");
    $io->write("Now run 'composer install' to get latest dependencies."); 
  }

  /**
   * Generate Salt file.
   *
   * Regenerate salt.txt file and use it settings.php.
   *
   * Use `file_get_contents($app_root . '/' . $site_path . '/salt.txt')`;
   *
   * @param Composer\Script\Event $event
   *   Sent by composer.
   */
  public static function generateSalt(Event $event) {
    $io = $event->getIo();
    $fs = new FileSystem();
    $process = new ProcessExecutor($io);
    $drupalRoot = static::getDrupalRoot();
    $salt_file = $drupalRoot . "/sites/default/salt.txt";
    $fs->chmod($drupalRoot . "/sites/default", 0775);
    if ($fs->exists($salt_file)) {
      $io->write("Removing old salt.txt file...");
      $process->execute("rm -f " . $salt_file);
    }
    $io->write("Generating " . $salt_file);
    $salt = Crypt::randomBytesBase64(55);
    $process->execute("touch " . $salt_file);
    $process->execute("echo " . $salt . " > " . $salt_file);
    $io->write($salt);
  }

  /**
   * Generate OpenSSL keys.
   * This is useful for headless Drupal (have a look a ContentaCMS).
   *
   * @param Event $event
   */
  public static function generateOpenSslKeys(Event $event) {
    $fs = new Filesystem();
    $drupalFinder = new DrupalFinder();
    $drupalFinder->locateRoot(getcwd());
    $certificatesRoot = $drupalFinder->getComposerRoot() . "/certificates";
    $process = new ProcessExecutor($event->getIO());
    
    if (!$fs->exists($certificatesRoot)) {
      $fs->mkdir($certificatesRoot, 0755);
    }

    $event->getIO()->write("Removing old keys");
    $process->execute("rm -f " . $certificatesRoot . "/*.key");
    
    $event->getIO()->write("Generate new keys");
    $process->execute("openssl genrsa -out " . $certificatesRoot . "/private.key 2048");
    $process->execute("openssl rsa -in " . $certificatesRoot . "/private.key -pubout > " . $certificatesRoot . "/public.key");

    // Fix keys permissions.
    self::fixOpenSslKeysPermissions($event);
  }
  
  /**
   * Fix OpenSSL keys files permission.
   *
   * @param Event $event
   */
  public static function fixOpenSslKeysPermissions(Event $event) {
    $drupalFinder = new DrupalFinder();
    $drupalFinder->locateRoot(getcwd());
    $certificatesRoot = $drupalFinder->getComposerRoot() . "/certificates";

    $process = new ProcessExecutor($event->getIO());

    $event->getIO()->write("Fixing OpenSSL keys permissions");
    $process->execute("chmod 600 " . $certificatesRoot . "/*.key");
  }

  public static function fixPermissions(Event $event) {
    $drupalRoot = static::getDrupalRoot();
    $process = new ProcessExecutor($event->getIO());
    $event->getIO()->write("Fixing files permissions");
    $process->execute("find " . $drupalRoot . " -type d -exec chmod u=rwx,g=rx,o= '{}' \;");
    $event->getIO()->write("Fixing folder permissions");
    $process->execute("find " . $drupalRoot . " -type f -exec chmod u=rw,g=r,o= '{}' \;");
  }

  /**
   * Reset Drupal site settings.
   *
   * @param Event $event
   */
  public static function siteInstall(Event $event) {
    self::loadSettings();
    $drush = self::getDrush();
    $drupalRoot = static::getDrupalRoot();
    $process = new ProcessExecutor($event->getIO());
    $event->getIO()->write("Reinstall Drupal");
    $process->execute($drush . " site-install " . getenv('INSTALL_PROFILE') . " -y -r " . $drupalRoot);
  }
  /**
   * Reset Drupal user.
   *
   * @param Event $event
   */
  public static function siteUser(Event $event) {
    self::loadSettings();
    $drush = self::getDrush();
    $drupalRoot = static::getDrupalRoot();
    $process = new ProcessExecutor($event->getIO());
    $event->getIO()->write("Create site user");
    $process->execute($drush . ' user-create ' . getenv('ACCOUNT_NAME') . ' --password="' . getenv('ACCOUNT_PASSWORD') . '" --mail="' . getenv('ACCOUNT_MAIL') . '" -y -r ' . $drupalRoot);
    $process->execute($drush . ' user-password ' . getenv('ACCOUNT_NAME') . ' --password="' . getenv('ACCOUNT_PASSWORD') . '" -y -r ' . $drupalRoot);
    foreach (explode(',',getenv('ACCOUNT_ROLE')) as $role) {
      if (!empty($role)) {
        $process->execute($drush . ' user-add-role "' . $role . '" --mail="' . getenv('ACCOUNT_MAIL') . '" -y -r ' . $drupalRoot);
      }
    }
  }

  /**
   * PHPCodeSniffer automatically fixing coding standards.
   *
   * @param Event $event
   */
  public static function phpcbf(Event $event) {
    $folders = self::getCustomFolders();
    
    $process = new ProcessExecutor($event->getIO());
    $event->getIO()->write("============");
    $event->getIO()->write("Start PHPCBF");
    $event->getIO()->write("============");
    $process->execute('./bin/phpcbf --config-set installed_paths vendor/drupal/coder/coder_sniffer');
    $process->execute('./bin/phpcbf --standard=Drupal --ignore="node_modules,bower_modules,vendor,*.md" --extensions="php,inc/php,module/php,theme/php" --colors ' . implode(' ', $folders) . ' > logs/errors_coding_practice.log');
    $process->execute('cat ./logs/errors_coding_practice.log');
    $event->getIO()->write("PHPCBF completed");
  }

  /**
   * PHPCodeSniffer.
   *
   * @param Event $event
   */
  public static function phpcs(Event $event) {
    $folders = self::getCustomFolders();
    $process = new ProcessExecutor($event->getIO());
    $event->getIO()->write("====================");
    $event->getIO()->write("Start PHPCodeSniffer");
    $event->getIO()->write("====================");
    $process->execute('./bin/phpcs --config-set installed_paths vendor/drupal/coder/coder_sniffer');
    $process->execute('./bin/phpcs --standard=Drupal --ignore="node_modules,bower_modules,vendor,*.md" --extensions="php,inc/php,module/php,theme/php" ' . implode(' ', $folders) . ' > logs/errors_coding_standard.log');
    $process->execute('cat ./logs/errors_coding_standard.log');
    $event->getIO()->write("PHPCodeSniffer completed");
  }
}
