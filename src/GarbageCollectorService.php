<?php

namespace SilverStripe\GarbageCollector;

use Monolog\Logger;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;

class GarbageCollectorService
{
    use Configurable;

    /**
     * @internal
     * @var self
     */
    private static $instance;
    
    /**
     * Collectors registered for processing
     *
     * @var string[] Array of ClassNames for collectors to process
     */
    private static $collectors = [];

    private static $dependencies = [
        'logger' => '%$' . LoggerInterface::class,
    ];

    /** @var Logger */
    private $logger;

    /**
     * Public method for setting logger
     *
     * @param LoggerInterface $logger
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return self
     *
     * Uses Injector for dependancies instead of static
     */
    public static function inst()
    {
        return self::$instance ? self::$instance : self::$instance = Injector::inst()->get(self::class);
    }

    /**
     * Array of collectors for processing
     *
     * @return CollectorInterface[] Array of Collectors
     */
    public function getCollectors(): array
    {
        $collectors = [];
        
        foreach ($this->config()->get('collectors') as $collector) {
            $collectors[] = Injector::inst()->get($collector);
        }

        return $collectors;
    }

    /**
     * Process all registered Collectors
     */
    public function process(): void
    {
        foreach ($this->getCollectors() as $collector) {
            $this->processCollector($collector);
        }
    }

    /**
     * Array of processors for processing
     *
     * @return ProcessorInterface[] Array of Processors
     */
    public function getProcessors(CollectorInterface $collector): array
    {
        $processors = [];

        // Group processor by class to reduce duplication
        foreach ($collector->getProcessors() as $processor) {
            $processors[Injector::inst()->get($processor)->getImplementorClass()] = $processor;
        }

        return $processors;
    }

    /**
     * @param CollectorInterface $collector Collector to process
     */
    public function processCollector(CollectorInterface $collector)
    {
        $processors = $this->getProcessors($collector);

        // If no processors are present, skip.
        if (empty($processors)) {
            $this->logger->notice('No processors registered with Collector');
            return;
        }

        // Process collections
        foreach ($collector->getCollections() as $collection) {
            $this->processCollection($collection, $processors);
        }
    }

    /**
     * Process array of Collection using array of Processors (if matching)
     *
     * @param array $collection Array of collection data
     * @param array $processors Array of Processors
     */
    public function processCollection(array $collection, array $processors)
    {
        if (empty($processors)) {
            $this->logger->notice('No Processors provided for Collection');
            return;
        }
        
        foreach ($collection as $item) {
            if (is_array($item) || $item instanceof \Traversable && !$item instanceof DataObject) {
                // If traversable object is provided, loop through items to process;
                $this->processCollection($item, $processors);
            } else {
                // Otherwise loop through processors and execute.
                foreach ($processors as $instance => $processor) {
                    if ($item instanceof $instance) {
                        try {
                            // Use Injector to create processor and execute
                            $proc = Injector::inst()->create($processor, $item);
                            $records = $proc->process();

                            $this->logger->info(sprintf('Processed %d records for %s using %s', $records, get_class($item), $proc->getName()));
                        } catch (\Exception $e) {
                            // Log failures and continue;
                            // TODO: Stop re-processing of failed deletion records and expose it for audit.
                            $this->logger->error(sprintf('Unable to process records: "%s"', $e->getMessage()));
                        }
                        
                        // Move on to next item
                        continue 2;
                    }
                }
                // No processor was able to be found.
                $this->logger->notice(sprintf('Unable to find processor for %s', get_class($item)));
            }
        }
    }
}
