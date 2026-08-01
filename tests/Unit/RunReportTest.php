<?php

use Shahirul22\LaravelPiiSanitizer\ChunkReport;
use Shahirul22\LaravelPiiSanitizer\ChunkStatus;
use Shahirul22\LaravelPiiSanitizer\ModelReport;
use Shahirul22\LaravelPiiSanitizer\RunReport;

it('reports a model as failed when any chunk rolled back', function () {
    $failing = new ModelReport(
        modelClass: 'App\\Models\\User',
        table: 'users',
        chunkSize: 10,
        automaticChunkSize: true,
        rowsScanned: 10,
        expectedChunks: 1,
        chunks: [
            new ChunkReport(index: 1, rowCount: 10, status: ChunkStatus::RolledBack),
        ],
    );

    $passing = new ModelReport(
        modelClass: 'App\\Models\\User',
        table: 'users',
        chunkSize: 10,
        automaticChunkSize: true,
        rowsScanned: 10,
        expectedChunks: 1,
        chunks: [
            new ChunkReport(index: 1, rowCount: 10, status: ChunkStatus::Completed),
        ],
    );

    expect($failing->failed())->toBeTrue();
    expect($passing->failed())->toBeFalse();
});

it('returns only the completed chunks', function () {
    $report = new ModelReport(
        modelClass: 'App\\Models\\User',
        table: 'users',
        chunkSize: 10,
        automaticChunkSize: true,
        rowsScanned: 40,
        expectedChunks: 4,
        chunks: [
            new ChunkReport(index: 1, rowCount: 10, status: ChunkStatus::Completed),
            new ChunkReport(index: 2, rowCount: 10, status: ChunkStatus::Completed),
            new ChunkReport(index: 3, rowCount: 10, status: ChunkStatus::Completed),
            new ChunkReport(index: 4, rowCount: 10, status: ChunkStatus::RolledBack),
        ],
    );

    expect($report->completedChunks())->toHaveCount(3);
});

it('sums rows sanitized across completed chunks only', function () {
    $report = new ModelReport(
        modelClass: 'App\\Models\\User',
        table: 'users',
        chunkSize: 10,
        automaticChunkSize: true,
        rowsScanned: 30,
        expectedChunks: 3,
        chunks: [
            new ChunkReport(index: 1, rowCount: 10, status: ChunkStatus::Completed),
            new ChunkReport(index: 2, rowCount: 10, status: ChunkStatus::Completed),
            new ChunkReport(index: 3, rowCount: 10, status: ChunkStatus::RolledBack),
        ],
    );

    expect($report->rowsSanitized())->toBe(20);
});

it('reports chunks that were never attempted', function () {
    $report = new ModelReport(
        modelClass: 'App\\Models\\User',
        table: 'users',
        chunkSize: 500,
        automaticChunkSize: true,
        rowsScanned: 2000,
        expectedChunks: 20,
        chunks: [
            new ChunkReport(index: 1, rowCount: 500, status: ChunkStatus::Completed),
            new ChunkReport(index: 2, rowCount: 500, status: ChunkStatus::Completed),
            new ChunkReport(index: 3, rowCount: 500, status: ChunkStatus::Completed),
            new ChunkReport(index: 4, rowCount: 500, status: ChunkStatus::RolledBack),
        ],
    );

    expect($report->completedChunks())->toHaveCount(3);
    expect($report->rowsSanitized())->toBe(1500);
    expect($report->chunksNotAttempted())->toBe(16);
});

it('never reports a negative not-attempted count', function () {
    $report = new ModelReport(
        modelClass: 'App\\Models\\User',
        table: 'users',
        chunkSize: 500,
        automaticChunkSize: true,
        rowsScanned: 500,
        expectedChunks: 1,
        chunks: [
            new ChunkReport(index: 1, rowCount: 500, status: ChunkStatus::Completed),
            new ChunkReport(index: 2, rowCount: 500, status: ChunkStatus::Completed),
        ],
    );

    expect($report->chunksNotAttempted())->toBe(0);
});

it('reports a skipped model with no chunks and a skip reason', function () {
    $report = new ModelReport(
        modelClass: 'App\\Models\\Post',
        table: 'posts',
        chunkSize: 0,
        automaticChunkSize: false,
        rowsScanned: 0,
        expectedChunks: 0,
        chunks: [],
        skipReason: 'no-sanitizer',
    );

    expect($report->failed())->toBeFalse();
    expect($report->rowsSanitized())->toBe(0);
    expect($report->skipReason)->toBe('no-sanitizer');
});

it('aggregates model reports', function () {
    $failing = new ModelReport(
        modelClass: 'App\\Models\\User',
        table: 'users',
        chunkSize: 10,
        automaticChunkSize: true,
        rowsScanned: 10,
        expectedChunks: 1,
        chunks: [
            new ChunkReport(index: 1, rowCount: 10, status: ChunkStatus::RolledBack),
        ],
    );

    $passing = new ModelReport(
        modelClass: 'App\\Models\\Post',
        table: 'posts',
        chunkSize: 10,
        automaticChunkSize: true,
        rowsScanned: 10,
        expectedChunks: 1,
        chunks: [
            new ChunkReport(index: 1, rowCount: 10, status: ChunkStatus::Completed),
        ],
    );

    $run = new RunReport(models: [$passing, $failing], dryRun: false);

    expect($run->failed())->toBeTrue();
    expect($run->rowsSanitized())->toBe(10);
});

it('serialises to an array carrying the completed and not-completed accounting', function () {
    $model = new ModelReport(
        modelClass: 'App\\Models\\User',
        table: 'users',
        chunkSize: 10,
        automaticChunkSize: true,
        rowsScanned: 20,
        expectedChunks: 2,
        chunks: [
            new ChunkReport(index: 1, rowCount: 10, status: ChunkStatus::Completed),
            new ChunkReport(index: 2, rowCount: 10, status: ChunkStatus::RolledBack, failureClass: RuntimeException::class, failureMessage: 'boom'),
        ],
    );

    $run = new RunReport(models: [$model], dryRun: false);

    $array = $run->toArray();

    expect($array)->toHaveKeys(['dryRun', 'models']);
    expect($array['dryRun'])->toBeFalse();
    expect($array['models'])->toHaveCount(1);

    $chunks = $array['models'][0]['chunks'];

    expect($chunks[0])->toMatchArray([
        'index' => 1,
        'rowCount' => 10,
        'status' => 'completed',
        'failureClass' => null,
        'failureMessage' => null,
    ]);

    expect($chunks[1])->toMatchArray([
        'index' => 2,
        'rowCount' => 10,
        'status' => 'rolled_back',
        'failureClass' => RuntimeException::class,
        'failureMessage' => 'boom',
    ]);

    expect($array['models'][0]['rowsSanitized'])->toBe(10);
    expect($array['models'][0]['chunksNotAttempted'])->toBe(0);
});
