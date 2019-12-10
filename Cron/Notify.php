<?php

namespace JonathanMartz\MagentoUpdateNotify\Cron;

use \Magento\Framework\App\ProductMetadataInterface;
use \Magento\Framework\Translate\Inline\StateInterface;
use \Magento\Framework\Mail\Template\TransportBuilder;
use \Magento\Store\Model\Store;
use \Magento\Framework\App\Area;
use \Psr\Log\LoggerInterface;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use \JoliCode\Slack\ClientFactory;
use \JoliCode\Slack\Exception\SlackErrorResponse;

/**
 * Class Notify
 * @package JonathanMartz\MagentoUpdateNotify\Cron
 */
class Notify
{
    /**
     * Recipient email config path
     */
    const XML_PATH_EMAIL_RECIPIENT = 'trans_email/ident_general/email';
    /**
     * Contact Email
     */
    const XML_PATH_CONTACT_EMAIL = 'magentoupdatenotify/general/contact_email';
    /**
     * Contact Website
     */
    const XML_PATH_CONTACT_WEBSITE = 'magentoupdatenotify/general/contact_website';

    /**
     * Major Release
     */
    const XML_PATH_MAJOR_RELEASE = 'magentoupdatenotify/general/major_release';

    /**
     * Minor Release
     */
    const XML_PATH_MINOR_RELEASE = 'magentoupdatenotify/general/minor_release';

    /**
     * Patch Release
     */
    const XML_PATH_PATCH_RELEASE = 'magentoupdatenotify/general/patch_release';

    /**
     * Email Developer
     */
    const XML_PATH_EMAIL_DEVELOPER = 'magentoupdatenotify/general/email_developer';

    /**
     * Email Customer
     */
    const XML_PATH_EMAIL_CUSTOMER = 'magentoupdatenotify/general/email_customer';

    /**
     * Email Customer
     */
    const XML_PATH_ENABLED_SLACK = 'magentoupdatenotify/general/enable_slack';

    /**
     * Email Customer
     */
    const XML_PATH_ENABLED_EMAIL = 'magentoupdatenotify/general/enable_email';

    /**
     * Email Customer
     */
    const XML_PATH_SLACK_TOKEN = 'magentoupdatenotify/general/slack_token';

    /**
     * Email Customer
     */
    const XML_PATH_SLACK_USERNAME = 'magentoupdatenotify/general/slack_username';

    /**
     * Email Customer
     */
    const XML_PATH_SLACK_CHANNEL = 'magentoupdatenotify/general/slack_channel';

    /**
     * Module enabled
     */
    const XML_PATH_ENABLED = 'magentoupdatenotify/general/enable';

    /**
     * @var ProductMetadataInterface
     */
    protected $productMetadata;

    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StateInterface
     */
    protected $inlineTranslation;
    /**
     * @var TransportBuilder
     */
    protected $transportBuilder;

    /**
     * Notify constructor.
     * @param LoggerInterface $logger
     * @param ProductMetadataInterface $productMetadata
     * @param ScopeConfigInterface $scopeConfig
     * @param StateInterface $inlineTranslation
     * @param TransportBuilder $transportBuilder
     */
    public function __construct(
        LoggerInterface $logger,
        ProductMetadataInterface $productMetadata,
        ScopeConfigInterface $scopeConfig,
        StateInterface $inlineTranslation,
        TransportBuilder $transportBuilder
    )
    {
        $this->logger = $logger;
        $this->productMetadata = $productMetadata;
        $this->scopeConfig = $scopeConfig;
        $this->inlineTranslation = $inlineTranslation;
        $this->transportBuilder = $transportBuilder;
    }

    /**
     * @return bool|string
     */
    public function getLatestMagentoVersion()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, 'magento-version-checker');
        curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/magento/magento2/releases');
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_NOBODY, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }

    /**
     * @param $json
     * @return string
     */
    public function loadLatestMagentoVersion($json)
    {
        if (!empty($json)) {
            $data = json_decode($json, true);

            foreach ($data as $key => $value) {
                if (!$this->releaseNotDevelop($value['tag_name'])) {
                    return $value['tag_name'];
                }
            }
        }

        return 'unknown';
    }

    /**
     * @param $version
     * @return false|int
     */
    public function releaseNotDevelop($version)
    {
        return strpos($version, 'develop');
    }

    /**
     * @return bool
     */
    public function notifyOnMajorRelease(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::XML_PATH_MAJOR_RELEASE);
    }

    /**
     * @return bool
     */
    public function notifyOnMinorRelease(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::XML_PATH_MINOR_RELEASE);
    }

    /**
     * @param $version
     * @param $latest
     * @return bool
     */
    public function isMajorRelease($version, $latest)
    {
        return substr($version, 0, 3) !== substr($latest, 0, 3);
    }

    /**
     * @param $version
     * @param $latest
     * @return bool
     */
    public function isMinorRelease($version, $latest)
    {
        return substr($version, 0, 5) !== substr($latest, 0, 5);
    }

    public function isEnabled()
    {
        return (bool)$this->scopeConfig->getValue(self::XML_PATH_ENABLED);
    }

    public function isPatchRelease($version, $latest)
    {
        return $version !== $latest;
    }

    public function notifyOnPatchRelease()
    {
        return (bool)$this->scopeConfig->getValue(self::XML_PATH_PATCH_RELEASE);
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        if ($this->isEnabled()) {
            $version = $this->productMetadata->getVersion();

            $data = $this->getLatestMagentoVersion();
            $latest = $this->loadLatestMagentoVersion($data);

            if ($this->isPatchRelease($version, $latest) && $this->notifyOnPatchRelease()) {

                if ($this->isSlackEnabled()) {
                    $this->sendSlackMessage($version, $latest, 'patch');
                }

                if ($this->isEmailEnabled()) {
                    $this->sendEmail($version, $latest, 'patch');
                }
            } else {
                if ($this->isMinorRelease($version, $latest) && $this->notifyOnMinorRelease()) {
                    if ($this->isSlackEnabled()) {
                        $this->sendSlackMessage($version, $latest, 'minor');
                    }
                    if ($this->isEmailEnabled()) {
                        $this->sendEmail($version, $latest, 'minor');
                    }
                } else {
                    if ($this->isMajorRelease($version, $latest) && $this->notifyOnMajorRelease()) {
                        if ($this->isSlackEnabled()) {
                            $this->sendSlackMessage($version, $latest, 'major');
                        }
                        if ($this->isEmailEnabled()) {
                            $this->sendEmail($version, $latest, 'major');
                        }
                    }
                }
            }
        }
    }

    /**
     * @param string $version
     * @return false|string
     */
    public function getShortVersion(string $version)
    {
        return substr($version, 0, 3);
    }

    /**
     * @param string $release
     * @return string
     */
    public function generateMessageRelease(string $release)
    {
        if ($release == 'major') {
            return 'This is a Major release change which means it is an big update.';
        }
        return 'This is a Minor release change which means it is an small update.';
    }

    /**
     * @param string $version
     * @param string $latest
     */
    public function sendEmail(string $version, string $latest, string $release)
    {
        $sender = $this->scopeConfig->getValue(self::XML_PATH_EMAIL_RECIPIENT);
        $developer = $this->scopeConfig->getValue(self::XML_PATH_EMAIL_DEVELOPER);
        $customer = $this->scopeConfig->getValue(self::XML_PATH_EMAIL_CUSTOMER);

        $emails = [];

        if (!empty($customer)) {
            $emails[] = $customer;
        }

        if (!empty($developer)) {
            $emails[] = $developer;
        }

        try {
            $this->inlineTranslation->suspend();
            $sender = [
                'name' => "Magento2 Store",
                'email' => $sender,
            ];
            $transport = $this->transportBuilder
                ->setTemplateIdentifier('magento_update_notify')
                ->setTemplateOptions(
                    [
                        'area' => Area::AREA_FRONTEND,
                        'store' => Store::DEFAULT_STORE_ID,
                    ]
                )
                ->setTemplateVars([
                    'version' => $version,
                    'latest' => $latest,
                    'short_version' => $this->getShortVersion($latest),
                    'email' => $this->scopeConfig->getValue(self::XML_PATH_CONTACT_EMAIL),
                    'website' => $this->scopeConfig->getValue(self::XML_PATH_CONTACT_WEBSITE),
                    'release_notice' => $this->generateMessageRelease($release)
                ])
                ->setFromByScope($sender);

            if (count($emails) === 0) {
                $transport = $transport->addTo($sender['email']);
            } else if (count($emails) === 1) {
                $transport = $transport->addTo($emails[0]);
            } else if (count($emails) === 2) {
                $transport = $transport->addTo($emails[0]);
                $transport = $transport->addCc($emails[1]);
            }

            $transport = $transport->getTransport();

            $transport->sendMessage();
            $this->inlineTranslation->resume();
        } catch (\Exception $e) {
            $this->logger->info('MagentoUpdateNotify: ' . $e->getMessage());
        }
    }

    public function sendSlackMessage(string $version, string $latest, string $release)
    {
        $client = ClientFactory::create($this->getSlackToken());
        try {
            $result = $client->chatPostMessage([
                'username' => $this->getSlackUsername(),
                'channel' => $this->getSlackChannel(),
                'text' => $this->getMessage($version,$latest,$release)
            ]);
        } catch (SlackErrorResponse $e) {
            $this->logger->error('Fail to send the message.' . $e->getMessage());
        }
    }

    public function isSlackEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::XML_PATH_ENABLED_SLACK);
    }

    public function isEmailEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::XML_PATH_ENABLED_EMAIL);
    }

    public function getSlackToken(): string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_SLACK_TOKEN);
    }

    public function getSlackUsername(): string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_SLACK_USERNAME);
    }

    public function getSlackChannel(): string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_SLACK_CHANNEL);
    }

    public function getMessage(string $version, string $latest, string $release): string
    {
        $message = [];

        $message[] = 'Looks like your Shop needs an update.';
        $message[] = 'Your current Version is ' . $version . '.';
        $message[] = 'The latest Version is ' . $latest . '.';
        $message[] = 'If you want to know which changes are made in the newest version take a look at.';
        $message[] = 'https://devdocs.magento.com/guides/v' . $this->getShortVersion($latest) . '/release-notes/bk-release-notes.html';

        return implode(PHP_EOL, $message);
    }
}

