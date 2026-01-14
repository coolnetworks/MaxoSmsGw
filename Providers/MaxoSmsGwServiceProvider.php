<?php

namespace Modules\MaxoSmsGw\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Mail\Events\MessageSending;
use App\Conversation;

define('MAXOSMSGW_MODULE', 'maxosmsgw');

class MaxoSmsGwServiceProvider extends ServiceProvider
{
    const SMS_DOMAIN = 'sms.voipportal.com.au';

    public function boot()
    {
        \Log::info('MaxoSmsGw: Module booting...');
        $this->registerConfig();
        $this->registerHooks();
        $this->registerMailListener();
        \Log::info('MaxoSmsGw: Module boot complete, mail listener registered');
    }

    public function register()
    {
        //
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'maxosmsgw');
    }

    protected function registerHooks()
    {
        // Mark conversations from SMS gateway to prevent auto-replies
        \Eventy::addAction('conversation.created_by_customer', function ($conversation, $thread, $customer) {
            if ($this->isFromSmsGateway($customer->email ?? '')) {
                // Set conversation to prevent auto-reply by marking it
                // FreeScout checks the 'auto_reply_sent' flag
                $conversation->auto_reply_sent = true;
                $conversation->save();
            }
        }, 20, 3);

        // Also hook into customer reply to prevent auto-replies on replies
        \Eventy::addAction('conversation.customer_replied', function ($conversation, $thread, $customer) {
            if ($this->isFromSmsGateway($customer->email ?? '')) {
                $conversation->auto_reply_sent = true;
                $conversation->save();
            }
        }, 20, 3);

        // Prevent including previous messages in replies to SMS gateway
        \Eventy::addFilter('jobs.send_reply_to_customer.send_previous_messages', function ($send_previous, $last_thread, $threads, $conversation, $customer) {
            if ($this->isFromSmsGateway($customer->email ?? '')) {
                return false;
            }
            return $send_previous;
        }, 20, 5);

        // Strip the reply to just the text content (no signature, no quoted text)
        \Eventy::addFilter('email.reply_to_customer.threads', function ($threads, $conversation, $mailbox) {
            $customer = $conversation->customer;

            if (!$customer || !$this->isFromSmsGateway($customer->email ?? '')) {
                return $threads;
            }

            // Only keep the most recent thread and strip it down
            if (!empty($threads)) {
                foreach ($threads as $thread) {
                    $thread->body = $this->stripToPlainText($thread->body);
                }
                // Only send the latest reply, not the full thread history
                return [$threads[0]];
            }

            return $threads;
        }, 20, 3);

        // Also filter the mail body directly before sending
        \Eventy::addFilter('mail.customer_email_body', function ($body, $conversation, $thread) {
            $customer = $conversation->customer ?? null;

            if ($customer && $this->isFromSmsGateway($customer->email ?? '')) {
                return $this->stripToPlainText($body);
            }

            return $body;
        }, 20, 3);

        // Strip ticket number from subject line for SMS replies
        \Eventy::addFilter('email.reply_to_customer.subject', function ($subject, $conversation, $thread) {
            $customer = $conversation->customer ?? null;

            if ($customer && $this->isFromSmsGateway($customer->email ?? '')) {
                return $this->stripTicketNumber($subject);
            }

            return $subject;
        }, 20, 3);

    }

    /**
     * Register Laravel Mail event listener to intercept ALL outgoing emails
     */
    protected function registerMailListener()
    {
        $this->app['events']->listen(MessageSending::class, function (MessageSending $event) {
            try {
                $message = $event->message;

                // Get recipient email(s) - SwiftMailer returns array of email => name
                $recipients = [];
                if (method_exists($message, 'getTo')) {
                    $to = $message->getTo();
                    if (is_array($to)) {
                        // SwiftMailer: array keys are email addresses
                        $recipients = array_keys($to);
                    }
                }

                if (empty($recipients)) {
                    return;
                }

                // Check if any recipient is from SMS gateway
                $isSmsGateway = false;
                foreach ($recipients as $email) {
                    if ($this->isFromSmsGateway($email)) {
                        $isSmsGateway = true;
                        break;
                    }
                }

                if (!$isSmsGateway) {
                    return;
                }

                \Log::error('MaxoSmsGw: SMS gateway recipient detected: ' . implode(', ', $recipients));

                // Get the email body content (SwiftMailer)
                $content = '';
                if (method_exists($message, 'getBody')) {
                    $content = $message->getBody();
                }

                // Also check for HTML alternative parts
                if (method_exists($message, 'getChildren')) {
                    foreach ($message->getChildren() as $child) {
                        if (method_exists($child, 'getContentType') &&
                            strpos($child->getContentType(), 'text/html') !== false) {
                            $content = $child->getBody();
                            break;
                        }
                    }
                }

                \Log::error('MaxoSmsGw: Original content length: ' . strlen($content));

                // Strip to plain text
                $plainText = $this->stripToPlainText($content);

                \Log::error('MaxoSmsGw: Stripped content: ' . substr($plainText, 0, 200));

                // Replace the body with plain text only (SwiftMailer)
                if (method_exists($message, 'setBody')) {
                    // Set body as plain text
                    $message->setBody($plainText, 'text/plain');

                    // Remove all child parts (HTML alternatives, attachments, etc.)
                    if (method_exists($message, 'getChildren')) {
                        $children = $message->getChildren();
                        foreach ($children as $child) {
                            $message->detach($child);
                        }
                    }

                    // Also set content type header explicitly
                    if (method_exists($message, 'setContentType')) {
                        $message->setContentType('text/plain');
                    }
                }

                \Log::error('MaxoSmsGw: Processed SMS email successfully');

            } catch (\Exception $e) {
                \Log::error('MaxoSmsGw: Error processing email: ' . $e->getMessage());
            }
        });
    }

    /**
     * Check if an email address is from the SMS gateway domain
     */
    protected function isFromSmsGateway($email)
    {
        if (empty($email)) {
            return false;
        }

        $domain = self::SMS_DOMAIN;
        return (bool) preg_match('/@' . preg_quote($domain, '/') . '$/i', $email);
    }

    /**
     * Strip HTML content to plain text, removing signatures, quoted text, and formatting
     */
    protected function stripToPlainText($html)
    {
        if (empty($html)) {
            return '';
        }

        // Remove "Please reply above this line" separator and everything after (various formats)
        $html = preg_replace('/(<br\s*\/?>|\n)*--\s*Please reply above this line\s*--.*$/is', '', $html);
        $html = preg_replace('/(<br\s*\/?>|\n)*-+\s*Please reply above this line\s*-+.*$/is', '', $html);
        $html = preg_replace('/(<br\s*\/?>|\n)*[-─—]+\s*Please reply above this line\s*[-─—]+.*$/is', '', $html);
        $html = preg_replace('/.*Please reply above this line.*$/im', '', $html);
        $html = preg_replace('/.*reply above this line.*$/im', '', $html);

        // Remove signature blocks (common patterns)
        // -- signature delimiter
        $html = preg_replace('/(<br\s*\/?>|\n)*--\s*(<br\s*\/?>|\n).*$/is', '', $html);

        // Remove quoted/forwarded content
        // Pattern: "On [date] [person] wrote:" style quotes
        $html = preg_replace('/(<br\s*\/?>|\n)*On\s+.+wrote:.*$/is', '', $html);

        // Remove blockquote elements
        $html = preg_replace('/<blockquote[^>]*>.*?<\/blockquote>/is', '', $html);

        // Remove elements with class containing "signature" or "gmail_signature"
        $html = preg_replace('/<[^>]+class\s*=\s*["\'][^"\']*signature[^"\']*["\'][^>]*>.*?<\/[^>]+>/is', '', $html);

        // Remove divs that are signature blocks
        $html = preg_replace('/<div[^>]+class\s*=\s*["\'][^"\']*gmail_signature[^"\']*["\'][^>]*>.*?<\/div>/is', '', $html);

        // Remove "Sent from my iPhone/Android" etc
        $html = preg_replace('/(<br\s*\/?>|\n)*Sent from my\s+\w+.*$/is', '', $html);

        // Remove FreeScout signature marker content
        $html = preg_replace('/<div[^>]*data-signature[^>]*>.*?<\/div>/is', '', $html);

        // Convert <br> and </p> to newlines before stripping tags
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<\/div>/i', "\n", $html);

        // Strip remaining HTML tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Remove ticket numbers like [#123], (#123), #123, Ticket #123, etc.
        $text = $this->stripTicketNumber($text);

        // Final cleanup - remove any remaining "reply above this line" text (plain text version)
        $text = preg_replace('/.*[Pp]lease reply above this line.*(\n|$)/m', '', $text);
        $text = preg_replace('/.*[Rr]eply above this line.*(\n|$)/m', '', $text);
        $text = preg_replace('/^[-─—\s]+$/m', '', $text);

        // Trim
        $text = trim($text);

        return $text;
    }

    /**
     * Strip ticket/reference numbers from text
     * Handles formats like: [#123], (#123), #123, Ticket #123, Re: [#123], etc.
     */
    protected function stripTicketNumber($text)
    {
        if (empty($text)) {
            return '';
        }

        // Remove [#123] or [#ABC-123] style (square brackets)
        $text = preg_replace('/\s*\[#[A-Za-z0-9\-]+\]\s*/i', ' ', $text);

        // Remove (#123) style (parentheses)
        $text = preg_replace('/\s*\(#[A-Za-z0-9\-]+\)\s*/i', ' ', $text);

        // Remove {#123} style (curly braces)
        $text = preg_replace('/\s*\{#[A-Za-z0-9\-]+\}\s*/i', ' ', $text);

        // Remove "Ticket #123" or "Ticket#123" style
        $text = preg_replace('/\bTicket\s*#[A-Za-z0-9\-]+\b/i', '', $text);

        // Remove "Case #123" style
        $text = preg_replace('/\bCase\s*#[A-Za-z0-9\-]+\b/i', '', $text);

        // Remove "Ref: #123" or "Reference: #123" style
        $text = preg_replace('/\b(Ref|Reference)\s*:?\s*#[A-Za-z0-9\-]+\b/i', '', $text);

        // Remove standalone #123 at start of subject (common pattern)
        $text = preg_replace('/^#[A-Za-z0-9\-]+\s*:?\s*/i', '', $text);

        // Remove Re: Fwd: prefixes if they're now orphaned
        $text = preg_replace('/^(Re|Fwd|Fw)\s*:\s*(?=(Re|Fwd|Fw)\s*:|$)/i', '', $text);

        // Clean up multiple spaces and trim
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }
}
