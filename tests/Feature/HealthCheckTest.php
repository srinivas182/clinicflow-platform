<?php

it('reports platform health on the versioned API', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('service', 'clinicflow-platform')
        ->assertJsonPath('version', config('clinicflow.version'))
        ->assertJsonPath('region', 'af-south-1')
        ->assertJsonStructure(['status', 'service', 'version', 'region', 'time']);
});

it('keeps the framework health route for load balancers', function (): void {
    $this->get('/up')->assertOk();
});
