<?php
/**
 * Reprocess all existing SMS gateway conversations:
 * 1. Merge multiple conversations per phone number into one
 * 2. Strip all thread bodies (gateway HTML, confirmation boilerplate, #!)
 *
 * Run via: cd /var/www/html && sudo -n -u www-data php Modules/MaxoSmsGw/reprocess_sms.php
 */

// Bootstrap Laravel
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$smsDomain = 'sms.voipportal.com.au';

// --- Body stripping functions ---

function stripInboundSmsBody($body) {
    if (empty($body)) return '';

    // Remove <style> blocks and <img> tags before stripping HTML
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $body);
    $html = preg_replace('/<img[^>]*>/is', '', $html);

    $plain = strip_tags($html);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Try "[sender] wrote:" ... "Reply directly to this email"
    if (preg_match('/[\w\d]+\s+wrote:\s*(.*?)\s*Reply directly to this email/is', $plain, $m)) {
        $msg = trim($m[1]);
        if (!empty($msg)) return $msg;
    }

    // Fallback: "wrote:" ... footer
    if (preg_match('/wrote:\s*(.*?)\s*(?:Reply directly|Sign off your message|SMS replies are charged)/is', $plain, $m)) {
        $msg = trim($m[1]);
        if (!empty($msg)) return $msg;
    }

    // Last fallback: remove known boilerplate lines
    $plain = preg_replace('/^.*wrote:\s*$/m', '', $plain);
    $plain = preg_replace('/Reply directly to this email.*$/is', '', $plain);
    $plain = preg_replace('/Sign off your message.*$/is', '', $plain);
    $plain = preg_replace('/SMS replies are charged.*$/is', '', $plain);
    $plain = preg_replace('/\n{3,}/', "\n\n", $plain);
    $plain = trim($plain);

    return !empty($plain) ? $plain : trim(strip_tags($html));
}

function stripSmsConfirmation($body) {
    if (empty($body)) return '';

    $plain = strip_tags($body);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (preg_match('/Email2SMS Reply From [^:]+:(.*?)(?:#!|$)/s', $plain, $m)) {
        $msg = trim($m[1]);
        if (!empty($msg)) return $msg;
    }

    return $body;
}

function cleanSmsBoilerplate($text) {
    if (empty($text)) return '';

    // Remove CSS rules that leaked through
    $text = preg_replace('/[a-z,\s]*\{[^}]*\}\s*/i', '', $text);

    // Remove "-- Please reply above this line --" and everything after
    $text = preg_replace('/\s*-+\s*Please reply above this line\s*-+.*$/is', '', $text);

    // Remove "This reply was sent from ... to ..." and everything after
    $text = preg_replace('/\s*This reply was sent from\b.*$/is', '', $text);

    // Remove "Reply directly to this email" and everything after
    $text = preg_replace('/\s*Reply directly to this email.*$/is', '', $text);

    // Remove "Sign off your message with #!" and everything after
    $text = preg_replace('/\s*Sign off your message.*$/is', '', $text);

    // Remove "SMS replies are charged" and everything after
    $text = preg_replace('/\s*SMS replies are charged.*$/is', '', $text);

    // Remove "From: ... Sent: ..." quoted reply headers
    $text = preg_replace('/\s*From:\s+.*?\s+Sent:\s+.*$/is', '', $text);

    // Strip trailing #!
    $text = preg_replace('/\s*#!\s*$/', '', $text);

    // Clean up whitespace
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}

function cleanSmsBody($body) {
    if (empty($body)) return '';

    $plain = strip_tags($body);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Determine type and strip
    if (preg_match('/Email2SMS Reply From/i', $plain)) {
        $cleaned = stripSmsConfirmation($body);
    } else {
        $cleaned = stripInboundSmsBody($body);
    }

    // Final cleanup pass
    return cleanSmsBoilerplate($cleaned);
}

// --- Main processing ---

echo "Finding all SMS gateway conversations...\n";

$conversations = App\Conversation::where('customer_email', 'like', '%@' . $smsDomain)
    ->where('state', '!=', App\Conversation::STATE_DELETED)
    ->orderBy('created_at', 'asc')
    ->get();

echo "Found " . $conversations->count() . " SMS conversations\n";

// Group by customer_email (phone number)
$grouped = $conversations->groupBy('customer_email');

$mergeCount = 0;
$threadsCleaned = 0;
$threadsDeleted = 0;
$attachmentsDeleted = 0;
$conversationsDeleted = 0;

function isConfirmationEcho($body) {
    $plain = strip_tags($body);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return (bool) preg_match('/Email2SMS Reply From/i', $plain);
}

foreach ($grouped as $email => $convs) {
    echo "\n--- $email: " . $convs->count() . " conversation(s) ---\n";

    // Primary = oldest conversation
    $primary = $convs->first();

    // Merge duplicates into primary
    if ($convs->count() > 1) {
        foreach ($convs->slice(1) as $duplicate) {
            $threadCount = $duplicate->threads()->count();
            echo "  Merging conv #{$duplicate->id} ({$threadCount} threads) into #{$primary->id}\n";

            App\Thread::where('conversation_id', $duplicate->id)
                ->update(['conversation_id' => $primary->id]);

            $duplicate->state = App\Conversation::STATE_DELETED;
            $duplicate->save();

            $mergeCount++;
            $conversationsDeleted++;
        }
    }

    // Process all threads in the primary conversation
    $threads = $primary->threads()->get();
    foreach ($threads as $thread) {
        // Delete outbound confirmation echo threads entirely
        if (isConfirmationEcho($thread->body ?? '')) {
            // Delete attachments for this thread
            $thread->attachments()->each(function ($att) {
                $att->delete();
            });
            $thread->delete();
            $threadsDeleted++;
            echo "  Thread #{$thread->id} DELETED (confirmation echo)\n";
            continue;
        }

        // Delete attachments from remaining threads
        $attCount = $thread->attachments()->count();
        if ($attCount > 0) {
            $thread->attachments()->each(function ($att) {
                $att->delete();
            });
            $attachmentsDeleted += $attCount;
            echo "  Thread #{$thread->id}: deleted $attCount attachment(s)\n";
        }

        // Clean thread body
        $oldBody = $thread->body;
        $newBody = cleanSmsBody($oldBody);

        if ($newBody !== $oldBody) {
            $thread->body = $newBody;
            $thread->save();
            $threadsCleaned++;
            echo "  Thread #{$thread->id} cleaned: " . substr($newBody, 0, 80) . "\n";
        }
    }

    // Update primary conversation metadata
    $primary->threads_count = $primary->threads()->count();
    $latestThread = $primary->threads()->orderBy('created_at', 'desc')->first();
    if ($latestThread) {
        $primary->last_reply_at = $latestThread->created_at;
    }
    $primary->save();

    echo "  Primary #{$primary->id}: {$primary->threads_count} threads, last reply: {$primary->last_reply_at}\n";
}

echo "\n=== DONE ===\n";
echo "Conversations merged: $mergeCount\n";
echo "Conversations soft-deleted: $conversationsDeleted\n";
echo "Confirmation echo threads deleted: $threadsDeleted\n";
echo "Attachments deleted: $attachmentsDeleted\n";
echo "Threads cleaned: $threadsCleaned\n";
