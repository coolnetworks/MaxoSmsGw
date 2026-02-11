<?php
/**
 * Deduplicate SMS conversation threads:
 * 1. Remove consecutive threads with identical bodies (confirmation echoes)
 * 2. Remove duplicate action/lineitem entries (from conversation merges)
 *
 * Run via: cd /var/www/html && sudo -n -u www-data php Modules/MaxoSmsGw/dedup_sms.php
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$smsDomain = 'sms.voipportal.com.au';

$conversations = App\Conversation::where('customer_email', 'like', '%@' . $smsDomain)
    ->where('state', '!=', App\Conversation::STATE_DELETED)
    ->orderBy('created_at', 'asc')
    ->get();

echo "Found " . $conversations->count() . " SMS conversations\n";

$dupsDeleted = 0;
$actionsDeleted = 0;

foreach ($conversations as $conv) {
    $threads = $conv->threads()->orderBy('created_at', 'asc')->get();

    if ($threads->count() < 2) {
        continue;
    }

    $changed = false;

    // Pass 1: Remove consecutive threads with identical bodies
    $prevBody = null;
    $prevType = null;
    foreach ($threads as $thread) {
        $body = trim($thread->body ?? '');

        // Skip empty lineitem/action threads for body comparison
        if ($thread->type == App\Thread::TYPE_LINEITEM) {
            continue;
        }

        if ($prevBody !== null && $body === $prevBody && !empty($body)) {
            // Duplicate — delete the second one
            echo "  Conv #{$conv->id}: deleting duplicate thread #{$thread->id} (body: " . substr($body, 0, 60) . ")\n";
            $thread->attachments()->each(function ($a) { $a->delete(); });
            $thread->delete();
            $dupsDeleted++;
            $changed = true;
            continue;
        }

        $prevBody = $body;
        $prevType = $thread->type;
    }

    // Pass 2: Remove duplicate lineitem/action threads with same action_type at same timestamp
    $lineitems = $conv->threads()
        ->where('type', App\Thread::TYPE_LINEITEM)
        ->orderBy('created_at', 'asc')
        ->get();

    $seen = [];
    foreach ($lineitems as $li) {
        // Key by action_type + rounded timestamp (within same minute)
        $key = ($li->action_type ?? '') . '|' . substr($li->created_at, 0, 16);

        if (isset($seen[$key])) {
            echo "  Conv #{$conv->id}: deleting duplicate action thread #{$li->id} (action: {$li->action_type})\n";
            $li->delete();
            $actionsDeleted++;
            $changed = true;
        } else {
            $seen[$key] = true;
        }
    }

    if ($changed) {
        $conv->threads_count = $conv->threads()->count();
        $conv->save();
    }
}

echo "\n=== DONE ===\n";
echo "Duplicate message threads deleted: $dupsDeleted\n";
echo "Duplicate action threads deleted: $actionsDeleted\n";
