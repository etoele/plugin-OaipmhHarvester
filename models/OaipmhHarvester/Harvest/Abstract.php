<?php
/**
 * @package OaipmhHarvester
 * @subpackage Models
 * @copyright Copyright (c) 2009-2011 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

/**
 * Abstract class on which all other metadata format maps are based.
 *
 * @package OaipmhHarvester
 * @subpackage Models
 */
abstract class OaipmhHarvester_Harvest_Abstract
{
    /**
     * Notice message code, used for status messages.
     */
    const MESSAGE_CODE_NOTICE = 1;

    /**
     * Error message code, used for status messages.
     */
    const MESSAGE_CODE_ERROR = 2;

    /**
     * Date format for OAI-PMH requests.
     * Only use day-level granularity for maximum compatibility with
     * repositories.
     */
    const OAI_DATE_FORMAT = 'Y-m-d';

    /**
     * @var OaipmhHarvester_Harvest The OaipmhHarvester_Harvest object model.
     */
    private $_harvest;

    /**
     * @var SimpleXMLIterator The current, cached SimpleXMLIterator record object.
     */
    private $_record;

    private $_options = array(
        'public' => false,
        'featured' => false,
    );

    /**
     * Class constructor.
     *
     * Prepares the harvest process.
     *
     * @param OaipmhHarvester_Harvest $harvest The OaipmhHarvester_Harvest object
     * model
     * @return void
     */
    public function __construct($harvest)
    {
        // Set an error handler method to record run-time warnings (non-fatal
        // errors).
        set_error_handler(array($this, 'errorHandler'), E_WARNING);

        $this->_harvest = $harvest;

    }

    public function setOption($key, $value)
    {
        $this->_options[$key] = $value;
    }

    public function getOption($key)
    {
        return $this->_options[$key];
    }

    /**
      * JBH - 2020-01-22 to test if url exists
      */
    public function is404($url) {
      // Edit the four values below
      $PROXY_HOST = "git.etoele.com"; // Proxy server address
      $PROXY_PORT = "3128";    // Proxy server port

      stream_context_set_default(
       array(
        'http' => array(
         'proxy' => "tcp://$PROXY_HOST:$PROXY_PORT",
         'request_fulluri' => true,
        )
       )
      );
      $headers = get_headers($url, 1);
      _log("[OaipmhHarvester] url : " . (string) $url . " tested " . (string) $headers[0], Zend_Log::INFO);
      if(!preg_match('/(200|202|300|301|302|421)/', $headers[0])) return true; else return false;
    }
    /**
     * Abstract method that all class extentions must contain.
     *
     * @param SimpleXMLIterator The current record object
     */
    abstract protected function _harvestRecord($record);

    /**
     * Checks whether the current record has already been harvested, and
     * returns the record if it does.
     *
     * @param SimpleXMLIterator record to be harvested
     * @return OaipmhHarvester_Record|false The model object of the record,
     *      if it exists, or false otherwise.
     */
    private function _recordExists($xml)
    {
        $identifier = trim((string)$xml->header->identifier);

        /* Ideally, the OAI identifier would be globally-unique, but for
           poorly configured servers that might not be the case.  However,
           the identifier is always unique for that repository, so given
           already-existing identifiers, check against the base URL.
        */
        $table = get_db()->getTable('OaipmhHarvester_Record');
        $record = $table->findBy(
            array(
                'base_url' => $this->_harvest->base_url,
                'set_spec' => $this->_harvest->set_spec,
                'metadata_prefix' => $this->_harvest->metadata_prefix,
                'identifier' => (string)$identifier,
            ),
            1,
            1
        );

        // Ugh, gotta be a better way to do this.
        if ($record) {
            $record = $record[0];
        }
        return $record;
    }

    /**
     * Checks whether the current record has already been harvested, and
     * returns the record if it does.
     *
     * @param SimpleXMLIterator record to be harvested
     * @return OaipmhHarvester_Record|false The model object of the record,
     *      if it exists, or false otherwise.
     */
    private function _sruRecordExists($xml)
    {
        // SRU gives bot DC Header
        // $identifier = trim((string)$xml->identifier);
        if(isset($xml->recordData)){
          $identifier = $xml
                      ->recordData->children('oai_dc', TRUE)->dc->children('dc', TRUE)->identifier;
          $identifier =  trim($identifier);
          _log("[OaipmhHarvester] _sruRecordExists / identifier : " . $identifier, Zend_Log::INFO);
        }

        /* Ideally, the OAI identifier would be globally-unique, but for
           poorly configured servers that might not be the case.  However,
           the identifier is always unique for that repository, so given
           already-existing identifiers, check against the base URL.
        */
        $table = get_db()->getTable('OaipmhHarvester_Record');
        $record = $table->findBy(
            array(
                'base_url' => $this->_harvest->base_url,
                'set_spec' => $this->_harvest->set_spec,
                'metadata_prefix' => $this->_harvest->metadata_prefix,
                'identifier' => (string)$identifier,
            ),
            1,
            1
        );

        // Ugh, gotta be a better way to do this.
        if ($record) {
            $record = $record[0];
        }
        return $record;
    }

    private function _isIterable($var)
    {
        return (is_array($var) || $var instanceof Traversable);
    }

    /**
     * Recursive method that loops through all requested records
     *
     * This method hands off mapping to the class that extends off this one and
     * recurses through all resumption tokens until the response is completed.
     *
     * @param string|false $resumptionToken
     * @return string|boolean Resumption token if one exists, otherwise true
     * if the harvest is finished.
     */
    private function _harvestRecords()
    {

        // Iterate through the records and hand off the mapping to the classes
        // inheriting from this class.
        $response = $this->_harvest->listRecords();
        if ($this->_isIterable($response['records'])) {
            foreach ($response['records'] as $record) {
                $this->_harvestLoop($record);
            }
        } else {
            $this->_addStatusMessage("No records were found.");

        }

        // SRU dirty // HACK:
        if (!$this->_isIterable($response['records'])) {
          $response = $this->_harvest->listSruRecords();
          if ($this->_isIterable($response['records'])) {
              foreach ($response['records'] as $record) {
                $this->_harvestLoopSru($record);
              }
          } else {
              $this->_addStatusMessage("No SRU records were found.");
          }
        }

        $resumptionToken = @$response['resumptionToken'];
        if ($resumptionToken) {
            $this->_addStatusMessage("Received resumption token: $resumptionToken");
        } else {
            $this->_addStatusMessage("Did not receive a resumption token.");
        }

        return ($resumptionToken ? $resumptionToken : true);
    }

    /**
     * @internal Bad names for all of these methods, fixme.
     */
    private function _harvestLoop($record)
    {
        //Ignore (skip over) deleted records.
        if ($this->isDeletedRecord($record)) {
            return;
        }
        //Ignore (skip over) unwanted types records (Gallica).
        $typedoc = 'undefined';
        if (isset($record->header->setSpec)){
          foreach($record->header->setSpec as $value) {
            if((strpos($value, 'typedoc') !== false)) $typedoc =  substr($value, strrpos($text, 'typedoc:'));
          }
        }
        if (isset($typedoc)){
          if (strpos($typedoc, 'periodiques') !== false){
            return;
          } else {
            $typedoc = substr($typedoc, strrpos($typedoc, ':')+1);
          }
          _log("[OaipmhHarvester] _harvestLoop / Typedoc is $typedoc", Zend_Log::INFO);
        }

        $existingRecord = $this->_recordExists($record);
        $harvestedRecord = $this->_harvestRecord($record);

        // Cache the record for later use.
        $this->_record = $record;

        //Record has already been harvested
        if ($existingRecord) {
            // If datestamp has changed, update the record, otherwise ignore.
            // JBH 2020-11-17 - always process existing records ?
             //if($existingRecord->datestamp != $record->header->datestamp) {
             if(empty($existingRecord['fileMetadata']['files'])) {
                $this->_updateItem($existingRecord,
                                  $harvestedRecord['elementTexts'],
                                  $harvestedRecord['fileMetadata']);
             }
             //}
             if(empty($existingRecord['fileMetadata']['files'])){
               _log("[OaipmhHarvester] _harvestLoop / existingRecord has no file", Zend_Log::INFO);
             }
            _log("[OaipmhHarvester] _harvestLoop /" . print_r($existingRecord['fileMetadata']['files'], TRUE), Zend_Log::INFO);
            release_object($existingRecord);
        } else {
            _log("[OaipmhHarvester] _harvestLoop / \$this->_insertItem", Zend_Log::INFO);
            $this->_insertItem(
                $harvestedRecord['itemMetadata'],
                $harvestedRecord['elementTexts'],
                $harvestedRecord['fileMetadata']
            );

        }
    }

    /**
     * @internal Bad names for all of these methods, fixme.
     */
    private function _harvestLoopSru($record)
    {
        //Ignore (skip over) deleted records.
        if ($this->isDeletedRecord($record)) {
            return;
        }
        $existingRecord = $this->_sruRecordExists($record);
        $harvestedRecord = $this->_harvestRecord($record);

        // Cache the record for later use.
        $this->_record = $record;

        //Record has already been harvested
        if ($existingRecord) {
            // No datestamp in SRU results, lets update everything
            $this->_updateItem($existingRecord,
                                  $harvestedRecord['elementTexts'],
                                  $harvestedRecord['fileMetadata']);
            release_object($existingRecord);
            _log("[OaipmhHarvester] _harvestLoop / existingRecord : release_object(\$existingRecord)", Zend_Log::INFO);
        } else {
            _log("[OaipmhHarvester] _harvestLoop / \$this->_insertItem", Zend_Log::INFO);
            $this->_insertItem(
                $harvestedRecord['itemMetadata'],
                $harvestedRecord['elementTexts'],
                $harvestedRecord['fileMetadata']
            );

        }
    }

    /**
     * Return whether the record is deleted
     *
     * @param SimpleXMLIterator The record object
     * @return bool
     */
    public function isDeletedRecord($record)
    {
        if (isset($record->header) && isset($record->header->attributes()->status)
            && $record->header->attributes()->status == 'deleted') {
            return true;
        }
        return false;
    }

    /**
     * Insert a record into the database.
     *
     * @param Item $item The item object corresponding to the record.
     * @return void
     */
    private function _insertRecord($item)
    {
        $record = new OaipmhHarvester_Record;

        $record->harvest_id = $this->_harvest->id;
        $record->item_id    = $item->id;
        $record->identifier = (string) $this->_record->header->identifier;
        $record->datestamp  = (string) $this->_record->header->datestamp;
        $record->save();

        release_object($record);
    }

    /**
     * Update a record in the database with information from this harvest.
     *
     * @param OaipmhHarvester_Record The model object corresponding to the record.
     */
    private function _updateRecord(OaipmhHarvester_Record $record)
    {
        $record->datestamp  = (string) $this->_record->header->datestamp;
        $record->save();
    }

    /**
     * Return the current, formatted date.
     *
     * @return string
     */
    private function _getCurrentDateTime()
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Template method.
     *
     * May be overwritten by classes that extend of this one. This method runs
     * once, prior to record iteration.
     *
     * @see self::__construct()
     */
    protected function _beforeHarvest()
    {
    }

    /**
     * Template method.
     *
     * May be overwritten by classes that extend of this one. This method runs
     * once, after record iteration.
     *
     * @see self::__construct()
     */
    protected function _afterHarvest()
    {
    }

    /**
     * Insert a collection.
     *
     * @see insert_collection()
     * @param array $metadata
     * @return Collection
     */
    final protected function _insertCollection($metadata = array())
    {
        // If collection_id is not null, use the existing collection, do not
        // create a new one.
        if (($collection_id = $this->_harvest->collection_id)) {
            $collection = get_db()->getTable('Collection')->find($collection_id);
        }
        else {
            // There must be a collection name, so if there is none, like when the
            // harvest is repository-wide, set it to the base URL.
            if (!isset($metadata['elementTexts']['Dublin Core']['Title']['text']) ||
                    !$metadata['elementTexts']['Dublin Core']['Title']['text']) {
                $$metadata['elementTexts']['Dublin Core']['Title']['text'] = $this->_harvest->base_url;
            }

            $collection = insert_collection($metadata['metadata'],$metadata['elementTexts']);

            // Remember to set the harvest's collection ID once it has been saved.
            $this->_harvest->collection_id = $collection->id;
            $this->_harvest->save();
        }
        return $collection;
    }

    /**
     * Convenience method for inserting an item and its files.
     *
     * Method used by map writers that encapsulates item and file insertion.
     * Items are inserted first, then files are inserted individually. This is
     * done so Item and File objects can be released from memory, avoiding
     * memory allocation issues.
     *
     * @see insert_item()
     * @see insert_files_for_item()
     * @param mixed $metadata Item metadata
     * @param mixed $elementTexts The item's element texts
     * @param mixed $fileMetadata The item's file metadata
     * @return true
     */
    final protected function _insertItem(
        $metadata = array(),
        $elementTexts = array(),
        $fileMetadata = array()
    ) {
        // Insert the item.
        $item = insert_item($metadata, $elementTexts);

        // Insert the record after the item is saved. The idea here is that the
        // OaipmhHarvester_Records table should only contain records that have
        // corresponding items.
        $this->_insertRecord($item);

        // If there are files, insert one file at a time so the file objects can
        // be released individually.
        if (isset($fileMetadata['files'])) {

            // The default file transfer type is URL.
            $fileTransferType = isset($fileMetadata['file_transfer_type'])
                              ? $fileMetadata['file_transfer_type']
                              : 'Url';

            // The default option is ignore invalid files.
            $fileOptions = isset($fileMetadata['file_ingest_options'])
                         ? $fileMetadata['file_ingest_options']
                         : array('ignore_invalid_files' => true);

            // Prepare the files value for one-file-at-a-time iteration.
            $files = array($fileMetadata['files']);

            foreach ($files as $file) {
                $fileOb = insert_files_for_item(
                    $item,
                    $fileTransferType,
                    $file,
                    $fileOptions);
                $fileObject= $fileOb;
                if (!empty($file['metadata'])) {
                    $fileObject->addElementTextsByArray($file['metadata']);
                    $fileObject->save();
                }

                // Release the File object from memory.
                release_object($fileObject);
            }
        }

        // Release the Item object from memory.
        release_object($item);

        return true;
    }

    /**
     * Convenience method for inserting an item and its files.
     *
     * Method used by map writers that encapsulates item and file insertion.
     * Items are inserted first, then files are inserted individually. This is
     * done so Item and File objects can be released from memory, avoiding
     * memory allocation issues.
     *
     * @see insert_item()
     * @see insert_files_for_item()
     * @param OaipmhHarvester_Record $itemId ID of item to update
     * @param mixed $elementTexts The item's element texts
     * @param mixed $fileMetadata The item's file metadata
     * @return true
     */
    final protected function _updateItem(
        $record,
        $elementTexts = array(),
        $fileMetadata = array()
    ) {
        // Update the item
        $item = update_item(
            $record->item_id,
            array('overwriteElementTexts' => true),
            $elementTexts,
            $fileMetadata
        );

        // Update the datestamp stored in the database for this record.
        $this->_updateRecord($record);

        // Release the Item object from memory.
        release_object($item);

        return true;
    }

    /**
     * Adds a status message to the harvest.
     *
     * @param string $message The error message
     * @param int|null $messageCode The message code
     * @param string $delimiter The string dilimiting each status message
     */
    final protected function _addStatusMessage(
        $message,
        $messageCode = null,
        $delimiter = "\n\n"
    ) {
      // JBH 2020-12-09 - avoid Mysqli statement execute error : Data too long for column 'status_messages'
      $message = substr($message,0, 200);
      $this->_harvest->addStatusMessage($message, $messageCode, $delimiter);
    }

    /**
     * Return this instance's OaipmhHarvester_Harvest object.
     *
     * @return OaipmhHarvester_Harvest
     */
    final protected function _getHarvest()
    {
        return $this->_harvest;
    }

    /**
     * Convenience method that facilitates the building of a correctly formatted
     * elementTexts array.
     *
     * @see insert_item()
     * @param array $elementTexts The previously build elementTexts array
     * @param string $elementSet This element's element set
     * @param string $element This element text's element
     * @param mixed $text The text
     * @param bool $html Flag whether this element text is HTML
     * @return array
     */
    protected function _buildElementTexts(
        array $elementTexts = array(),
        $elementSet,
        $element,
        $text,
        $html = false
    ) {
        $elementTexts[$elementSet][$element][]
            = array('text' => (string) $text, 'html' => (bool) $html);
        return $elementTexts;
    }

    /**
     * Error handler callback.
     *
     * @see self::__construct()
     */
    public function errorHandler($errno, $errstr, $errfile, $errline)
    {
        if (!error_reporting()) {
            return false;
        }
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /**
     * Harvest records from the OAI-PMH repository.
     */
    final public function harvest()
    {
        try {
            $this->_harvest->status =
                OaipmhHarvester_Harvest::STATUS_IN_PROGRESS;
            $this->_harvest->save();

            $this->_beforeHarvest();
            // This method does most of the actual work.
            $resumptionToken = $this->_harvestRecords();

            // A return value of true just indicates success, all other values
            // must be valid resumption tokens.
            if ($resumptionToken === true) {
                $this->_afterHarvest();
                $this->_harvest->status =
                    OaipmhHarvester_Harvest::STATUS_COMPLETED;
                $this->_harvest->completed = $this->_getCurrentDateTime();
                $this->_harvest->resumption_token = null;
            } else {
                $this->_harvest->resumption_token = $resumptionToken;
                $this->_harvest->status =
                    OaipmhHarvester_Harvest::STATUS_QUEUED;
            }

            $this->_harvest->save();

        } catch (Zend_Http_Client_Exception $e) {
            $this->_stopWithError($e);
        } catch (Exception $e) {
            $this->_stopWithError($e);
            // For real errors need to be logged and debugged.
            _log($e, Zend_Log::ERR);
        }

        $peakUsage = memory_get_peak_usage();
        _log("[OaipmhHarvester] Peak memory usage: $peakUsage", Zend_Log::INFO);
    }

    private function _stopWithError($e)
    {
        $this->_addStatusMessage(substr($e->getMessage(), 0, 200), self::MESSAGE_CODE_ERROR);
        $this->_harvest->status = OaipmhHarvester_Harvest::STATUS_ERROR;
        // Reset the harvest start_from time if an error occurs during
        // processing. Since there's no way to know exactly when the
        // error occured, re-harvests need to start from the beginning.
        $this->_harvest->start_from = null;
        $this->_harvest->save();
    }

    public static function factory($harvest)
    {
        $classSuffix = Inflector::camelize($harvest->metadata_prefix);
        $class = 'OaipmhHarvester_Harvest_' . $classSuffix;
        require_once OAIPMH_HARVESTER_MAPS_DIRECTORY . "/$classSuffix.php";

        // Set the harvest object.
        $harvester = new $class($harvest);
        return $harvester;
    }
}
