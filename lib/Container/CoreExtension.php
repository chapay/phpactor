<?php

namespace Phpactor\Container;

use Composer\Autoload\ClassLoader;
use PhpBench\DependencyInjection\ExtensionInterface;
use Phpactor\Application\ClassCopy;
use Phpactor\Application\ClassMover as ClassMoverApp;
use Phpactor\Application\ClassReflector;
use Phpactor\Application\ClassSearch;
use Phpactor\Application\FileInfo;
use Phpactor\Application\FileInfoAtOffset;
use Phpactor\Application\Helper\ClassFileNormalizer;
use Phpactor\ClassFileConverter\Adapter\Composer\ComposerClassToFile;
use Phpactor\ClassFileConverter\Adapter\Composer\ComposerFileToClass;
use Phpactor\ClassFileConverter\Domain\ChainClassToFile;
use Phpactor\ClassFileConverter\Domain\ChainFileToClass;
use Phpactor\ClassFileConverter\Domain\ClassToFileFileToClass;
use Phpactor\ClassMover\ClassMover;
use Phpactor\Filesystem\Adapter\Composer\ComposerFileListProvider;
use Phpactor\Filesystem\Adapter\Composer\ComposerFilesystem;
use Phpactor\Filesystem\Adapter\Git\GitFilesystem;
use Phpactor\Filesystem\Adapter\Simple\SimpleFilesystem;
use Phpactor\Filesystem\Domain\ChainFileListProvider;
use Phpactor\Filesystem\Domain\Cwd;
use Phpactor\Filesystem\Domain\FilePath;
use Phpactor\TypeInference\Adapter\ClassToFile\ClassToFileSourceCodeLoader;
use Phpactor\TypeInference\Adapter\TolerantParser\TolerantTypeInferer;
use Phpactor\TypeInference\Adapter\WorseReflection\WorseMemberTypeResolver;
use Phpactor\TypeInference\Adapter\WorseReflection\WorseSourceCodeLocator;
use Phpactor\TypeInference\TypeInference;
use Phpactor\UserInterface\Console\Command\ClassCopyCommand;
use Phpactor\UserInterface\Console\Command\ClassNewCommand;
use Phpactor\UserInterface\Console\Command\ClassMoveCommand;
use Phpactor\UserInterface\Console\Command\ClassReflectorCommand;
use Phpactor\UserInterface\Console\Command\ClassSearchCommand;
use Phpactor\UserInterface\Console\Command\FileInfoAtOffsetCommand;
use Phpactor\UserInterface\Console\Command\FileInfoCommand;
use Phpactor\UserInterface\Console\Dumper\DumperRegistry;
use Phpactor\UserInterface\Console\Dumper\IndentedDumper;
use Phpactor\UserInterface\Console\Dumper\JsonDumper;
use Phpactor\UserInterface\Console\Dumper\TableDumper;
use Phpactor\UserInterface\Console\Prompt\BashPrompt;
use Phpactor\UserInterface\Console\Prompt\ChainPrompt;
use Phpactor\WorseReflection\Reflector;
use Phpactor\WorseReflection\SourceCodeLocator\ChainSourceLocator;
use Phpactor\WorseReflection\SourceCodeLocator\StringSourceLocator;
use Phpactor\WorseReflection\SourceCodeLocator\StubSourceLocator;
use Symfony\Component\Console\Application;
use Phpactor\UserInterface\Console\Command\ConfigDumpCommand;
use PhpBench\DependencyInjection\Container;
use Phpactor\WorseReflection\Logger\PsrLogger;
use Monolog\Logger;
use Phpactor\Application\Complete;
use Phpactor\UserInterface\Console\Command\CompleteCommand;

class CoreExtension implements ExtensionInterface
{
    const APP_NAME = 'phpactor';
    const APP_VERSION = '0.2.0';

    static $autoloader;

    public function getDefaultConfig()
    {
        return [
            'autoload' => 'vendor/autoload.php',
            'cwd' => getcwd(),
            'console_dumper_default' => 'indented',
            'reflector_stub_directory' => __DIR__ . '/../../vendor/jetbrains/phpstorm-stubs',
            'cache_dir' => __DIR__ . '/../../cache',
        ];
    }

    public function load(Container $container)
    {
        $this->registerMonolog($container);
        $this->registerConsole($container);
        $this->registerComposer($container);
        $this->registerClassToFile($container);
        $this->registerClassMover($container);
        $this->registerTypeInference($container);
        $this->registerSourceCodeFilesystem($container);
        $this->registerApplicationServices($container);
        $this->registerReflection($container);
    }

    private function registerMonolog(Container $container)
    {
        $container->register('monolog.logger', function (Container $container) {
			return new Logger('phpactor');
		});
	}

	private function registerConsole(Container $container)
	{
		// ---------------
		// Commands
		// ---------------
		$container->register('command.class_move', function (Container $container) {
			return new ClassMoveCommand(
				$container->get('application.class_mover'),
				$container->get('console.prompter')
			);
		}, [ 'ui.console.command' => []]);

		$container->register('command.class_copy', function (Container $container) {
			return new ClassCopyCommand(
				$container->get('application.class_copy'),
				$container->get('console.prompter')
			);
		}, [ 'ui.console.command' => []]);

		$container->register('command.class_search', function (Container $container) {
			return new ClassSearchCommand(
				$container->get('application.class_search')
			);
		}, [ 'ui.console.command' => []]);

		$container->register('command.file_offset', function (Container $container) {
			return new FileInfoAtOffsetCommand(
				$container->get('application.file_info_at_offset'),
				$container->get('console.dumper_registry')
			);
		}, [ 'ui.console.command' => []]);

		$container->register('command.file_info', function (Container $container) {
			return new FileInfoCommand(
				$container->get('application.file_info'),
				$container->get('console.dumper_registry')
			);
		}, [ 'ui.console.command' => []]);

		$container->register('command.class_reflector', function (Container $container) {
			return new ClassReflectorCommand(
				$container->get('application.class_reflector'),
				$container->get('console.dumper_registry')
			);
		}, [ 'ui.console.command' => []]);

		$container->register('command.config_dump', function (Container $container) {
			return new ConfigDumpCommand(
				$container->getParameters(),
				$container->get('console.dumper_registry'),
				$container->configLoader()
			);
		}, [ 'ui.console.command' => []]);

		$container->register('command.complete', function (Container $container) {
			return new CompleteCommand(
				$container->get('application.complete'),
				$container->get('console.dumper_registry')
			);
		}, [ 'ui.console.command' => []]);
		// ---------------
		// Dumpers
		// ---------------
		$container->register('console.dumper_registry', function (Container $container) {
			$dumpers = [];
			foreach ($container->getServiceIdsForTag('console.dumper') as $dumperId => $attrs) {
				$dumpers[$attrs['name']] = $container->get($dumperId);
			}

			return new DumperRegistry($dumpers, $container->getParameter('console_dumper_default'));
		});

		$container->register('console.dumper.indented', function (Container $container) {
			return new IndentedDumper();
		}, [ 'console.dumper' => ['name' => 'indented']]);

		$container->register('console.dumper.json', function (Container $container) {
			return new JsonDumper();
		}, [ 'console.dumper' => ['name' => 'json']]);

		$container->register('console.dumper.fieldvalue', function (Container $container) {
			return new TableDumper();
		}, [ 'console.dumper' => ['name' => 'fieldvalue']]);


		// ---------------
		// Misc
		// ---------------
		$container->register('console.prompter', function (Container $container) {
			return new ChainPrompt([
				new BashPrompt()
			]);
		});
	}

	private function registerComposer(Container $container)
	{
		$container->register('composer.class_loaders', function (Container $container) {
			$currentAutoloaders = spl_autoload_functions();
			$autoloaderPaths = (array) $container->getParameter('autoload');
			$autoloaders = [];

			foreach ($autoloaderPaths as $autoloaderPath) {
				if (!file_exists($autoloaderPath)) {
					throw new \InvalidArgumentException(sprintf(
						'Could not locate autoloaderPath file "%s"', $autoloaderPath
					));
				}

				$autoloader = require $autoloaderPath;

				if (!$autoloader instanceof ClassLoader) {
					throw new \RuntimeException('Autoloader is not an instance of ClassLoader');
				}

				$autoloaders[] = $autoloader;
			}

			foreach (spl_autoload_functions() as $autoloadFunction) {
				spl_autoload_unregister($autoloadFunction);
			}

			foreach ($currentAutoloaders as $autoloader) {
				spl_autoload_register($autoloader);
			}

			return $autoloaders;
		});
	}

	private function registerClassToFile(Container $container)
	{
		$container->register('class_to_file.converter', function (Container $container) {
			return new ClassToFileFileToClass(
				$container->get('class_to_file.class_to_file'),
				$container->get('class_to_file.file_to_class')
			);
		});

		$container->register('class_to_file.class_to_file', function (Container $container) {
			$classToFiles = [];
			foreach ($container->get('composer.class_loaders') as $classLoader) {
				$classToFiles[] = new ComposerClassToFile($classLoader);
			}

			return new ChainClassToFile($classToFiles);
		});

		$container->register('class_to_file.file_to_class', function (Container $container) {
			$fileToClasses = [];
			foreach ($container->get('composer.class_loaders') as $classLoader) {
				$fileToClasses[] =  new ComposerFileToClass($classLoader);
			}
			return new ChainFileToClass($fileToClasses);
		});
	}

	private function registerClassMover(Container $container)
	{
		$container->register('class_mover.class_mover', function (Container $container) {
			return new ClassMover();
		});
	}

	private function registerSourceCodeFilesystem(Container $container)
	{
		$container->register('source_code_filesystem.git', function (Container $container) {
			return new GitFilesystem(FilePath::fromString($container->getParameter('cwd')));
		});
		$container->register('source_code_filesystem.simple', function (Container $container) {
			return new SimpleFilesystem(FilePath::fromString($container->getParameter('cwd')));
		});
		$container->register('source_code_filesystem.composer', function (Container $container) {
			$providers = [];
			$cwd = FilePath::fromString($container->getParameter('cwd'));
			foreach ($container->get('composer.class_loaders') as $classLoader) {
				$providers[] = new ComposerFileListProvider($cwd, $classLoader);
			}
			return new SimpleFilesystem($cwd, new ChainFileListProvider($providers));
		});
	}

	private function registerTypeInference(Container $container)
	{
		$container->register('type_inference.source_code_loader', function (Container $container) {
			return new ClassToFileSourceCodeLoader($container->get('class_to_file.converter'));
		});
	}

	private function registerApplicationServices(Container $container)
	{
		$container->register('application.class_mover', function (Container $container) {
			return new ClassMoverApp(
				$container->get('application.helper.class_file_normalizer'),
				$container->get('class_mover.class_mover'),
				$container->get('source_code_filesystem.git')
			);
		});

		$container->register('application.class_copy', function (Container $container) {
			return new ClassCopy(
				$container->get('application.helper.class_file_normalizer'),
				$container->get('class_mover.class_mover'),
				$container->get('source_code_filesystem.git')
			);
		});

		$container->register('application.file_info', function (Container $container) {
			return new FileInfo(
				$container->get('class_to_file.converter'),
				$container->get('source_code_filesystem.simple')
			);
		});

		$container->register('application.file_info_at_offset', function (Container $container) {
			return new FileInfoAtOffset(
				$container->get('reflection.reflector'),
				$container->get('class_to_file.converter')
			);
		});

		$container->register('application.class_search', function (Container $container) {
			return new ClassSearch(
				$container->get('source_code_filesystem.composer'),
				$container->get('class_to_file.converter')
			);
		});

		$container->register('application.class_reflector', function (Container $container) {
			return new ClassReflector(
				$container->get('application.helper.class_file_normalizer'),
				$container->get('reflection.reflector')
			);
        });

		$container->register('application.complete', function (Container $container) {
			return new Complete(
				$container->get('reflection.reflector'),
				$container->get('application.helper.class_file_normalizer')
			);
		});

		$container->register('application.helper.class_file_normalizer', function (Container $container) {
			return new ClassFileNormalizer($container->get('class_to_file.converter'));
		});
	}

	private function registerReflection(Container $container)
	{
		$container->register('reflection.reflector', function (Container $container) {
			$locators = [];

			foreach (array_keys($container->getServiceIdsForTag('reflection.source_locator')) as $locatorId) {
				$locators[] = $container->get($locatorId);
			}
			return Reflector::create(
				new ChainSourceLocator($locators),
				new PsrLogger($container->get('monolog.logger'))
			);
		});

		$container->register('reflection.locator.stub', function (Container $container) {
			return new StubSourceLocator(
				// TODO: we do not need the location facility of the reflector in this case
				//       need to separate responsiblities
				Reflector::create(new StringSourceLocator(\Phpactor\WorseReflection\SourceCode::fromString(''))),
				$container->getParameter('reflector_stub_directory'),
				$container->getParameter('cache_dir')
			);
		}, [ 'reflection.source_locator' => []]);

		$container->register('reflection.locator.worse', function (Container $container) {
			return new WorseSourceCodeLocator(
				$container->get('type_inference.source_code_loader')
			);
		}, [ 'reflection.source_locator' => []]);

	}
}
