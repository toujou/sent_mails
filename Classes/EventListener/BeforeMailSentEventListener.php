<?php
declare(strict_types=1);

namespace StudioMitte\SentMails\EventListener;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Mail\Event\BeforeMailerSentMessageEvent;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

class BeforeMailSentEventListener  implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private array $blindedConfiguration = [
        'dsn' => '******',
        'transport_smtp_encrypt' => '******',
        'transport_smtp_password' => '******',
        'transport_smtp_server' => '******',
        'transport_smtp_username' => '******',
    ];

    public function __invoke(BeforeMailerSentMessageEvent $event): void
    {
        try {
            /** @var Email $sentMessage */
            $sentMessage = $event->getMessage();
            /** @var Email $originalMessage */
            $originalMessage = $event->getMessage();

            $customId = StringUtility::getUniqueId('mail_');

            $sentMessage->getHeaders()->addTextHeader('X-SentMail_Custom', $customId);

            $isReply = get_class($originalMessage) === RawMessage::class;

            $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_sentmail_mail');
            $connection->insert('tx_sentmail_mail', [
                'crdate' => time(),
                'subject' => $isReply ? '' : $originalMessage->getHeaders()->getHeaderBody('Subject'),
                'sender' => $this->convertAddresses($originalMessage->getFrom()),
                'receiver' => $this->convertAddresses( $originalMessage->getTo()),
                'bcc' => $this->convertAddresses($originalMessage->getBcc()),
                'cc' => $this->convertAddresses($originalMessage->getCc()),
                'debug' => '',
                'message_id' => $customId,
                'message' => $sentMessage->toString(),
                'original_message' => $originalMessage->toString(),
                'envelope_original' => serialize($event->getEnvelope() ?: Envelope::create($originalMessage)),
                'internal_id' => $customId,
                'email_serialized' => $originalMessage instanceof Email ? serialize($originalMessage) : '',
                'settings' => json_encode($this->getSettings()),
            ]);
            $sentMessage->getHeaders()->remove('X-SentMail_ID');
            $sentMessage->getHeaders()->addTextHeader('X-SentMail_ID', $connection->lastInsertId('tx_sentmail_mail'));

        } catch (\Throwable $e) {
            $this->logger->warning('[SentMails] Error during BeforeMailSentEventListener: ', [
                $e->getMessage(),
            ]);
        }
    }

    /**
     * @param Address|Address[] $addresses
     */
    protected function convertAddresses(Address|array $addresses): string
    {
        $converted = [];
        if ($addresses instanceof Address) {
            $addresses = [$addresses];
        }
        foreach ($addresses as $address) {
            $converted[] = [
                'name' => $address->getName(),
                'email' => $address->getAddress(),
                '_string' => $address->toString(),
            ];
        }
        return json_encode($converted, JSON_THROW_ON_ERROR);
    }

    protected function getSettings(): array
    {
        $settings = (array)$GLOBALS['TYPO3_CONF_VARS']['MAIL'];

        ArrayUtility::mergeRecursiveWithOverrule(
            $settings,
            ArrayUtility::intersectRecursive($settings, $this->blindedConfiguration)
        );

        ArrayUtility::naturalKeySortRecursive($settings);
        return $settings;
    }
}
