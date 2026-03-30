<?php

namespace Python_In_PHP\Plugin\Python\Traits;

trait CommandLineTrait
{
    /**
     * @return array{output: string, code: int}
     */
    public function runCommand(string $cmd): array
    {
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);
        $output = implode(PHP_EOL, $output);

        if (isset($this->output)){
            $this->output->verboseMessage("Running command: $cmd");
            $this->output->veryVerboseMessage("Output: " . PHP_EOL . $output);
        }

        return [
            'output' => $output,
            'code' => $returnCode,
        ];
    }
}