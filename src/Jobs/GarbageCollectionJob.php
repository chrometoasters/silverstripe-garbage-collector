<?php

namespace Silverstripe\GarbageCollection\Jobs;

use Exception;
use SilverStripe\ORM\Queries\SQLConditionalExpression;
use SilverStripe\ORM\DB;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * @property array $versions
 * @property array $remainingVersions
 */
class GarbageCollectionJob extends AbstractQueuedJob
{   
    /**
     * Constructor
     * 
     * @var string
     */
    public function __construct(CollectorInterface $collector)
    {
        $this->collector = $collector;
        $this->processors = [];

        foreach ($collector->getProcessors() as $processor) {
            $this->processors[$processor::getImplementor()] = $processor;
        }
    }

    /**
     * Defines the title of the job
     * @return string
     */
    public function getTitle()
    {
        return sprintf("Garbage Collection processing for %s collector", $this->collector->getName());
    }

    public function getJobType(): int
    {
        return QueuedJob::QUEUED;
    }

    public function setup(): void
    {
        $collections = $this->collector->getCollections();
        $this->remaining = $collections;
        $this->totalSteps = ceil(count($collections) / $this->batchSize);
    }

    /**
     * @throws Exception
     */
    public function process(): void
    {
        $remaining = $this->remaining;

        // check for trivial case
        if (count($remaining) === 0) {
            $this->isComplete = true;
            return;
        }

        if (count($this->processors) === 0) {
            throw new Exception(sprintf('No Processors found for collector %s', $this->collector->getName()));
        }
        
        $this->processCollection($remaining);

        // update job progress
        $this->remaining = $remaining;
        $this->currentStep += 1;

        // check for job completion
        if (count($remaining) > 0) {
            return;
        }

        $this->isComplete = true;
    }

    protected function processCollection(array $collection)
    {
        foreach ($collection as $item) {
            if ($item instanceof \Traversable) {
                // If traversable object is provided, loop through items to process;
                $this->processCollection($item);
            } else {
                // Otherwise loop through processors and execute.
                foreach ($this->processors as $instance => $processor) {
                    if ($item instanceof $instance) {
                        try {
                            $proc = new $processor($item);
                            $records = $proc->process();
                            $this->addMessage(sprintf('Processed %d records for %s using %s', $records, get_class($item), $proc->getName()));
                        } catch (Exception $e) {
                            // Log failures and continue;
                            // TODO: Stop re-processing of failed deletion records and expose it for audit.
                            $this->addMessage(springf('Unable to process records: "%s"', $e->getMessage()));
                        }
                        
                        // Move on to next item
                        continue 2;
                    }
                }
                // No processor was able to be found.
                $this->addMessage(sprintf('Unable to find processor for %s', get_class($item)));
            }
        }
    }
}