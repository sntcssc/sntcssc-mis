<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ViewClearCommand as BaseViewClearCommand;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'view:clear')]
class ViewClearCommand extends BaseViewClearCommand
{
    /**
     * Execute the console command.
     *
     * @return void
     *
     * @throws RuntimeException
     */
    public function handle()
    {
        $path = $this->laravel['config']['view.compiled'];

        if (! $path) {
            throw new RuntimeException('View path not found.');
        }

        $this->laravel['view.engine.resolver']
            ->resolve('blade')
            ->forgetCompiledOrNotExpired();

        foreach ($this->files->glob("{$path}/*") as $view) {
            if ($this->files->isDirectory($view)) {
                // Preserve the Livewire single-file component cache so active in-flight requests render safely
                if (basename($view) === 'livewire') {
                    continue;
                }
                $this->files->deleteDirectory($view);
            } else {
                $this->files->delete($view);
            }
        }

        $this->components->info('Compiled views cleared successfully.');
    }
}
