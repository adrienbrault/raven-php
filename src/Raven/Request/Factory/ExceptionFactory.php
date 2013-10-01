<?php

namespace Raven\Request\Factory;

use Raven\Request\Interfaces\Exception;
use Raven\Request\Interfaces\Frame;
use Raven\Request\Interfaces\SingleException;
use Raven\Request\Interfaces\StackTrace;
use Symfony\Component\Debug\Exception\FlattenException;

/**
 * @author Adrien Brault <adrien.brault@gmail.com>
 */
class ExceptionFactory
{
    /**
     * @param  \Exception $e
     * @return Exception
     */
    public function create(\Exception $e)
    {
        $singleExceptions = array();

        while ($e !== null) {
            $singleException = new SingleException($e->getMessage());
            $singleException->setType(get_class($e));
            $singleException->setModule(sprintf('%s:%s', $e->getFile(), $e->getLine()));
            $singleException->setStackTrace($this->createStackTrace($e));
            $singleExceptions[] = $singleException;

            $e = $e->getPrevious();
        }

        return new Exception(array_reverse($singleExceptions));
    }

    /**
     * @param  \Exception $e
     * @return StackTrace
     */
    public function createStackTrace(\Exception $e)
    {
        $flattenException = FlattenException::create($e);

        $frames = array();
        foreach ($flattenException->getTrace() as $entry) {
            $frames[] = $frame = new Frame(
                $entry['file'],
                (strlen($entry['class']) > 0 ? $entry['class'] . '::' : ''). $entry['function'],
                $entry['class']
            );
            $frame->setLineNumber($entry['line']);
            $frame->setVars($this->getFrameVars($entry));

            $this->handleFrameContext($frame, $entry);
        }

        return new StackTrace($frames);
    }

    private function getFrameVars($entry)
    {
        $vars = array();
        foreach ($entry['args'] as $arg) {
            $vars[] = is_array($arg[1]) ? $arg[0] : $arg[1]; // get the arg value
        }

        return $vars;
    }

    private function handleFrameContext(Frame $frame, $entry)
    {
        // TODO maybe cache this stuff, reading file on a production environment if bad
        if (is_readable($entry['file'])) {
            $fileContent = file_get_contents($entry['file']);
            $lines = explode("\n", $fileContent);
            $lineCount = count($lines);
            $lineIndex = $frame->getLineNumber() - 1;

            // TODO not sure this is well handled
            $frame->setContextLine($lines[$lineIndex]);
            $frame->setPreContext(array_slice(
                $lines,
                max(0, $lineIndex - 5),
                min(5, $lineIndex)
            ));
            $frame->setPostContext(array_slice(
                $lines,
                min($lineCount, $lineIndex + 1),
                min(5, $lineCount - $lineIndex)
            ));
        }
    }
}
