<?php

namespace JonathanMartz\MagentoUpdateNotify\Cron;

use \Magento\Framework\App\ProductMetadataInterface;
use \Magento\Framework\Translate\Inline\StateInterface;
use \Magento\Framework\Mail\Template\TransportBuilder;
use \Magento\Store\Model\Store;
use \Magento\Framework\App\Area;
use \Psr\Log\LoggerInterface;
use \Magento\Framework\App\Config\ScopeConfigInterface;

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
    const XML_PATH_CONTACT_EMAIL = 'magento/update_notify/contact_email';
    /**
     * Contact Website
     */
    const XML_PATH_CONTACT_WEBSITE = 'magento/update_notify/contact_website';

    /**
     * Major Release
     */
    const XML_PATH_MAJOR_RELEASE = 'magento/update_notify/major_release';

    /**
     * Minor Release
     */
    const XML_PATH_MINOR_RELEASE = 'magento/update_notify/minor_release';


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
                if (!$this->releaseNotDevelop($value['tag_name']) && strlen($value['tag_name']) <= 5) {
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
    public function notifyOnMajorRelease():bool{
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_MAJOR_RELEASE);
    }

    /**
     * @return bool
     */
    public function notifyOnMinorRelease():bool{
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_MINOR_RELEASE);
    }

    /**
     * @param $version
     * @param $latest
     * @return bool
     */
    public function isMajorRelease($version, $latest){
        return substr($version, 0,3) !== substr($latest, 0,3);
    }

    /**
     * @param $version
     * @param $latest
     * @return bool
     */
    public function isMinorRelease($version, $latest){
        return $version !== $latest;
    }

    /**
     * @return void
     */
    public function execute():void
    {
        $version = $this->productMetadata->getVersion();

        $data = $this->getLatestMagentoVersion();
        $latest = $this->loadLatestMagentoVersion($data);

        if($this->isMajorRelease($version, $latest) && $this->notifyOnMajorRelease()){
            $this->sendEmail($version, $latest, 'major');
        }
        else{
            if($this->isMinorRelease($version, $latest) && $this->notifyOnMinorRelease()){
                $this->sendEmail($version, $latest, 'minor');
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
    public function generateMessageRelease(string $release){
        if($release == 'major'){
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
                ->setFromByScope($sender)
                ->addTo($sender['email'])
                ->getTransport();
            $transport->sendMessage();
            $this->inlineTranslation->resume();
        } catch (\Exception $e) {
            $this->logger->info('Konalo: ' . $e->getMessage());
        }
    }
}

