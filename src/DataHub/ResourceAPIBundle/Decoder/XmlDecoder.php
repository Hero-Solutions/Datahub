<?php
namespace DataHub\ResourceAPIBundle\Decoder;

use FOS\RestBundle\Decoder\DecoderInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Datahub\ResourceBundle\Builder\ConverterFactoryInterface;

/**
 * Decodes XML data.
 *
 * @author Matthias Vandermaesen <matthias.vandermaesen@vlaamsekunstcollectie.be>
 * @package DataHub\ResourceAPIBundle
 */
class XmlDecoder implements DecoderInterface
{
    /**
     * @var Monolog\Logger
     */
    private $logger;

    /**
     * @var DataConverterInterface
     */
    private $converter;

    /**
     * Constructor
     *
     * @param Monolog\Logger $logger
     * @param ConverterFactoryInterface $converterFactory
     */
    public function __construct($logger, ConverterFactoryInterface $converterFactory)
    {
        $this->logger = $logger;
        $this->converter = $converterFactory->getConverter();

        $this->logger->debug('Initialized XMLDecoder');
    }

    /**
     * {@inheritdoc}
     */
    public function decode($data)
    {
        try {
            if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]|\xED[\xA0-\xBF].|\xEF\xBF[\xBE\xBF]/', $data)) {
                $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]|\xED[\xA0-\xBF].|\xEF\xBF[\xBE\xBF]/', "\xEF\xBF\xBD", $data);
            }
            $result = $this->converter->read($data);
        } catch (\Exception $e) {
            throw new BadRequestHttpException('Invalid XML: ' . $e->getMessage());
        }

        return $result;
    }
}





