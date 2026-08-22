<?php

/**
 * Artisan 自定义命令注册（闭包命令或后续类命令）。
 */

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Horizon 监控快照：用于沉淀队列吞吐、等待等时序指标。
 */
Schedule::command('horizon:snapshot')->everyFiveMinutes();

/**
 * GeoFlow 任务调度：每分钟扫描一次可执行任务并入队（对齐 bak cron 逻辑）。
 */
Schedule::command('geoflow:schedule-tasks')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(10);

/**
 * 临时数据清理：每日回收 MCP 审计与幂等键（保留窗口见 config/geoflow.php）。
 */
Schedule::command('geoflow:prune-transient')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping(60);
