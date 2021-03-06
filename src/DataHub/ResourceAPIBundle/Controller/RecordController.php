<?php

namespace DataHub\ResourceAPIBundle\Controller;

use DataHub\ResourceAPIBundle\Document\Record;
use DataHub\ResourceAPIBundle\Repository\RecordRepository;
use DataHub\SetBundle\Document\Set;
use DOMDocument;
use DOMXPath;
use FOS\RestBundle\Controller\Annotations as FOS;
use FOS\RestBundle\Request\ParamFetcherInterface;
use Hateoas\HateoasBuilder;
use Hateoas\Representation\CollectionRepresentation;
use Hateoas\Representation\OffsetRepresentation;
use JMS\Serializer\SerializationContext;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * REST controller for Records.
 *
 * @author  Kalman Olah <kalman@inuits.eu>
 * @author Matthias Vandermaesen <matthias.vandermaesen@vlaamsekunstcollectie.be>
 *
 * @todo This class needs heavy refactoring. There are several things to be done:
 *  - Use ParamConverters to convert the incoming XML to JSON encoded string and
 *    inject both representations into a simple Document Model.
 *  - Implement proper content negotation.
 *  - Implement event listeners to offload conversion JSON/XML and fetching of ids.
 *
 * @package DataHub\ResourceAPIBundle
 */
class RecordController extends Controller
{
    /**
     * List records.
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     statusCodes = {
     *       200 = "Returned when successful"
     *     }
     * )
     *
     * @FOS\Get("/data")
     *
     * @FOS\QueryParam(name="offset", requirements="\d+", nullable=true, description="Offset from which to start listing entries.")
     * @FOS\QueryParam(name="limit", requirements="\d{1,2}", default="5", description="How many entries to return.")
     * @FOS\QueryParam(name="sort", requirements="[a-zA-Z\.]+,(asc|desc|ASC|DESC)", nullable=true, description="Sorting field and direction.")
     *
     * @FOS\View(
     *     serializerGroups={"list"},
     *     serializerEnableMaxDepthChecks=true
     * )
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     *
     * @return array<mixed>
     */
    public function cgetRecordsAction(ParamFetcherInterface $paramFetcher, Request $request)
    {
        // get parameters
        $offset = intval($paramFetcher->get('offset'));
        $limit = intval($paramFetcher->get('limit'));
        // @todo
        //   Remove sorting, not relevant here

        $recordRepository = $this->get('datahub.resource_api.repository.default');
        $records = $recordRepository->findBy(array(), null, $limit, $offset);
        $total = $recordRepository->count();

        // @todo
        //   The record itself is stored as a plain JSON string in the document
        //   We need to decode it manually before we can pass the entire object
        //   off to the serializer. Put this in a separate handler / service.
        foreach ($records as &$record) {
            $json = $record->getJson();
            $json = json_decode($json, true);
            $record->setJson($json);
        }

        $offsetCollection = new OffsetRepresentation(
            new CollectionRepresentation(
                $records, 'records', 'records'
            ),
            'get_records',
            array(),
            $offset,
            $limit,
            $total
        );

        $context = SerializationContext::create()->setGroups(array('Default','json'));
        $json = $this->get('jms_serializer')->serialize($offsetCollection, 'json', $context);

        return new Response($json, Response::HTTP_OK, array('Content-Type' => 'application/hal+json'));
    }

    /**
     * Get a single record.
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     statusCodes = {
     *         200 = "Returned when successful",
     *         404 = "Returned if the resource was not found"
     *     }
     * )
     * @ParamConverter(class="DataHub\ResourceAPIBundle\Document\Record", converter="record_converter")
     * @FOS\Get("/data/{recordIds}", requirements={"recordIds" = ".+?"})
     * @FOS\View(
     *     serializerGroups={"single"},
     *     serializerEnableMaxDepthChecks=true
     * )
     *
     * @param Request $request the request object
     * @param string $id the internal id of the record
     *
     * @return mixed
     *
     * @throws NotFoundHttpException if the resource was not found
     */
    public function getRecordAction(Request $request, Record $record)
    {
        // Circumventing the XML serializer here, since we already have the
        // raw XML input from the store.
        if ($request->getRequestFormat() == 'xml') {
            return new Response($record->getRaw(), Response::HTTP_OK, array('Content-Type' => 'application/xml'));
        }

        // @todo
        //   The record itself is stored as a plain JSON string in the document
        //   We need to decode it manually before we can pass the entire object
        //   off to the serializer. Put this in a separate handler / service.
        $json = $record->getJson();
        $json = json_decode($json, true);
        $record->setJson($json);

        $hateoas = HateoasBuilder::create()->build();
        $context = SerializationContext::create()->setGroups(array('json'));
        $json = $hateoas->serialize($record, 'json', $context);

        return new Response($json, Response::HTTP_OK, array('Content-Type' => 'application/hal+json'));
    }

    /**
     * Create a record.
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     statusCodes = {
     *         201 = "Returned when successful",
     *         400 = "Returned if the form could not be validated, or record already exists",
     *     }
     * )
     *
     * @FOS\View(
     *     serializerGroups={"single"},
     *     serializerEnableMaxDepthChecks=true,
     *     statusCode=201
     * )
     *
     * @FOS\Post("/data")
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     * @return mixed
     * @throws \Exception
     */
    public function postRecordAction(ParamFetcherInterface $paramFetcher, Request $request)
    {
        $data = $request->request->all();

        if (empty($data)) {
            throw new UnprocessableEntityHttpException('No record was provided.');
        }

        // Fetch the datatype from the converter
        $factory = $this->get('datahub.resource.builder.converter.factory');
        $dataType = $factory->getConverter()->getDataType();

        // Get the (p)id's
        $dataPids = $dataType->getRecordId($data);
        $objectPids = $dataType->getObjectId($data);

        // Get the JSON & XML Raw variants of the record
        $variantJSON = json_encode($data);
        $variantXML = $request->getContent();

        // Fetch a dataPid. This will be the ID used in the database for this
        // record.
        // @todo Differentiate between 'preferred' and 'alternate' dataPids
        $dataPid = $dataPids[0];

        // Check whether record already exists
        $recordRepository = $this->get('datahub.resource_api.repository.default');
        $record = $recordRepository->findOneByProperty('recordIds', $dataPid);
        if ($record instanceof Record) {
            throw new ConflictHttpException('Record with this ID already exists.');
        }

        $documentManager = $this->get('doctrine_mongodb')->getManager();

        $record = new Record();
        $sets = $this->extractSetsFromRecord($variantXML, $documentManager);
        $record->setSets($sets);
        $record->setRecordIds($dataPids);
        $record->setObjectIds($objectPids);
        $record->setRaw($variantXML);
        $record->setJson($variantJSON);

        $documentManager->persist($record);
        $documentManager->flush();

        $id = $record->getId();

        if (!$id) {
            throw new BadRequestHttpException('Could not store new record.');
        } else {
            $response = Response::HTTP_CREATED;
            $headers = [
                'Location' => $request->getPathInfo() . '/' . urlencode($dataPid)
            ];
        }

        return new Response('', $response, $headers);
    }

    /**
     * Update a record (replaces the entire resource).
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     input = "DataHub\ResourceAPIBundle\Form\Type\DataFormType",
     *     statusCodes = {
     *         201 = "Returned when a record was succesfully created",
     *         204 = "Returned when an existing recurd was succesfully updated",
     *         400 = "Returned if the record could not be stored or parsed",
     *     }
     * )
     *
     * @FOS\View(
     *     serializerGroups={"single"},
     *     serializerEnableMaxDepthChecks=true
     * )
     *
     * @FOS\Put("/data/{id}", requirements={"id" = ".+?"})
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     * @param integer $id ID of entry to update
     *
     * @return mixed
     *
     * @throws NotFoundHttpException if the resource was not found
     */
    public function putRecordAction(Request $request, $id)
    {
        // Get a decoded record
        $record = $request->request->all();

        if (empty($record)) {
            throw new UnprocessableEntityHttpException('No record was provided.');
        }

        // Fetch the datatype from the converter
        $factory = $this->get('datahub.resource.builder.converter.factory');
        $dataType = $factory->getConverter()->getDataType();

        // Get the (p)id's
        $recordIds = $dataType->getRecordId($record);
        $objectIds = $dataType->getObjectId($record);

        // Get the JSON & XML Raw variants of the record
        $variantJSON = json_encode($record);
        $variantXML = $request->getContent();

        $documentManager = $this->get('doctrine_mongodb')->getManager();
        $recordRepository = $this->get('datahub.resource_api.repository.default');
        $record = $recordRepository->findOneByProperty('recordIds', $id);

        // If the record does not exist, create it, if it does exist, update the existing record.
        // The ID of a particular resource is not generated server side, but determined client side.
        // The ID is the resource URI to which the PUT request was made.
        //
        //   See: https://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html#sec9.6
        //
        if (!$record instanceof Record) {
            $record = new Record();
            $sets = $this->extractSetsFromRecord($variantXML, $documentManager);
            $record->setSets($sets);
            $record->setRecordIds($recordIds);
            $record->setObjectIds($objectIds);
            $record->setRaw($variantXML);
            $record->setJson($variantJSON);

            $documentManager->persist($record);
            $documentManager->flush();

            $id = $record->getId();

            if (!$id) {
                throw new BadRequestHttpException('Could not store new record.');
            } else {
                $response = Response::HTTP_CREATED;
                $headers = [];
            }
        } else {

            $sets = $this->extractSetsFromRecord($variantXML, $documentManager);
            $record->setSets($sets);
            $record->setRecordIds($recordIds);
            $record->setObjectIds($objectIds);
            $record->setRaw($variantXML);
            $record->setJson($variantJSON);

            $documentManager->flush();

            $id = $record->getId();

            if (!$id) {
                throw new BadRequestHttpException('Could not store updated record.');
            } else {
                $response = Response::HTTP_NO_CONTENT;
                $headers = [];
            }
        }

        return new Response('', $response, $headers);
    }

    /**
     * Delete a record.
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     statusCodes = {
     *         204 = "Returned when successful",
     *         404 = "Returned if the resource was not found"
     *     }
     * )
     *
     * @FOS\View(statusCode="204")
     * @FOS\Delete("/data/{recordIds}", requirements={"recordIds" = ".+?"})
     * @ParamConverter(class="DataHub\ResourceAPIBundle\Document\Record", converter="record_converter")
     *
     * @param Request $request the request object
     * @param integer $id ID of entry to delete
     *
     * @return mixed
     *
     * @throws NotFoundHttpException if the resource was not found
     */
    public function deleteRecordAction(Request $request, Record $record)
    {
        $documentManager = $this->get('doctrine_mongodb')->getManager();
        $documentManager->remove($record);
        $documentManager->flush();
    }

    private function extractSetsFromRecord($variantXML, $documentManager)
    {
        // Classify this record into a (number of) set(s)
        $sets = array();
        $setRepository = $this->get('datahub.set.repository.default');
        $setsDefinition = $this->getParameter('sets');
        $domDoc = new DOMDocument;
        $domDoc->loadXML($variantXML);
        $xpath = new DOMXPath($domDoc);
        foreach($setsDefinition as $setKey => $setDefinition) {
            if(is_array($setDefinition)) {
                // Loop over the array until we find a set
                $found = false;
                for($i = 0; $i < count($setDefinition) && !$found; $i++) {
                    $query = $this->buildXpath($setDefinition[$i], 'lido');
                    if($this->extractSet($sets, $query, $xpath, $setKey, $setRepository, $documentManager)) {
                        $found = true;
                    }
                }
            } else {
                $query = $this->buildXpath($setDefinition, 'lido');
                $this->extractSet($sets, $query, $xpath, $setKey, $setRepository, $documentManager);
            }
        }
        return $sets;
    }

    private function extractSet(&$sets, $query, $xpath, $setKey, $setRepository, $documentManager)
    {
        $foundSet = false;
        $extracted = $xpath->query($query);
        if ($extracted) {
            if (count($extracted) > 0) {
                foreach ($extracted as $extr) {
                    if ($extr->nodeValue !== 'n/a') {
                        $name = $extr->nodeValue;
                        $spec = $setKey . ':' . $this->cleanSpec($name);
                        $sets[] = $spec;
                        $foundSet = true;

                        // Check if this set already exists, otherwise create a new one
                        $set = $setRepository->findOneByProperty('spec', $spec);
                        if(!$set) {
                            $set = new Set();
                            $set->setSpec($spec);
                            $set->setName($name);
                            $documentManager->persist($set);
                            $documentManager->flush();
                        }
                    }
                }
            }
        }
        return $foundSet;
    }

    // Build the xpath based on the provided namespace
    private function buildXpath($xpath, $namespace, $language = null)
    {
        if($language != null) {
            $xpath = str_replace('{language}', $language, $xpath);
        }
        $xpath = str_replace('[@', '[@' . $namespace . ':', $xpath);
        $xpath = str_replace('[@' . $namespace . ':xml:', '[@xml:', $xpath);
        $xpath = preg_replace('/\[([^@])/', '[' . $namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\/([^\/])/', '/' . $namespace . ':${1}', $xpath);
        if(strpos($xpath, '/') !== 0) {
            $xpath = $namespace . ':' . $xpath;
        }
        $xpath = 'descendant::' . $xpath;
        return $xpath;
    }

    private function cleanSpec($spec)
    {
        $spec = strtolower($spec);
        $spec = preg_replace('/[^a-z0-9 _\-]/', '', $spec);
        $spec = str_replace(' ', '_', $spec);
        $spec = str_replace('-', '_', $spec);
        while(strpos($spec, '__') > -1) {
            $spec = str_replace('__', '_', $spec);
        }
        $spec = trim($spec);
        return $spec;
    }
}
