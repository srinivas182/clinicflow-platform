<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#12232E">
        <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
        <title inertia><?php echo e(config('app.name', 'Clinic Flow')); ?></title>
        <?php echo app('Illuminate\Foundation\Vite')('resources/js/app.tsx'); ?>
        <?php $__inertiaSsrResponse = app(\Inertia\Ssr\SsrState::class)->setPage($page)->dispatch();  if ($__inertiaSsrResponse) { echo $__inertiaSsrResponse->head; } ?>
    </head>
    <body class="font-sans antialiased bg-paper text-ink">
        <?php $__inertiaSsrResponse = app(\Inertia\Ssr\SsrState::class)->setPage($page)->dispatch();  if ($__inertiaSsrResponse) { echo $__inertiaSsrResponse->body; } else { ?><script data-page="app" type="application/json"><?php echo json_encode($page, JSON_HEX_TAG); ?></script><div id="app"></div><?php } ?>
    </body>
</html>
<?php /**PATH /home/claude/cf/platform/resources/views/app.blade.php ENDPATH**/ ?>