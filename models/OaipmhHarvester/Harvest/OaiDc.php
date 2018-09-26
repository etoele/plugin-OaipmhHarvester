<?php
/**
 * @package OaipmhHarvester
 * @subpackage Models
 * @copyright Copyright (c) 2009-2011 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

/**
 * Metadata format map for the required oai_dc Dublin Core format
 *
 * @package OaipmhHarvester
 * @subpackage Models
 */
class OaipmhHarvester_Harvest_OaiDc extends OaipmhHarvester_Harvest_Abstract
{
    /*  XML schema and OAI prefix for the format represented by this class.
        These constants are required for all maps. */
    const METADATA_SCHEMA = 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd';
    const METADATA_PREFIX = 'oai_dc';

    const OAI_DC_NAMESPACE = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
    const DUBLIN_CORE_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    /**
     * Collection to insert items into.
     * @var Collection
     */
    protected $_collection;

    /**
     * Actions to be carried out before the harvest of any items begins.
     */
     protected function _beforeHarvest()
    {
        $harvest = $this->_getHarvest();

        $collectionMetadata = array(
            'metadata' => array(
                'public' => $this->getOption('public'),
                'featured' => $this->getOption('featured'),
            ),);
        $collectionMetadata['elementTexts']['Dublin Core']['Title'][]
            = array('text' => (string) $harvest->set_name, 'html' => false);
        $collectionMetadata['elementTexts']['Dublin Core']['Description'][]
            = array('text' => (string) $harvest->set_Description, 'html' => false);

        $this->_collection = $this->_insertCollection($collectionMetadata);
    }

    /**
     * Harvest one record.
     *
     * @param SimpleXMLIterator $record XML metadata record
     * @return array Array of item-level, element texts and file metadata.
     */
    protected function _harvestRecord($record)
    {
        $itemMetadata = array(
            'collection_id' => $this->_collection->id,
            'public'        => $this->getOption('public'),
            'featured'      => $this->getOption('featured'),
        );
        if(isset($record->metadata)){
            $dcMetadata = $record
                        ->metadata
                        ->children(self::OAI_DC_NAMESPACE)
                        ->children(self::DUBLIN_CORE_NAMESPACE);
        }

       // JBH If using SRU, check wether Metadata exists
       if(isset($record->recordData)){
         // _log("[OaipmhHarvester] record->recordData : " . $record->recordData->children('oai_dc', TRUE)->dc->asXML(), Zend_Log::INFO);
         // _log("[OaipmhHarvester] record->extraRecordData : " . $record->extraRecordData->asXML(), Zend_Log::INFO);
         // _log("[OaipmhHarvester] record->extraRecordData->thumbnail : " . $record->extraRecordData.children('thumbnail', TRUE), Zend_Log::INFO);
         $dcMetadata = $record
                     ->recordData->children('oai_dc', TRUE)->dc->children('dc', TRUE);

         // if($thumbnail = $record->extraRecordData.children('thumbnail', TRUE)){
         //    _log("[OaipmhHarvester] Thumnail found : " . $thumbnail, Zend_Log::INFO);
         //    $dcMetadata.addChild('relation', $thumbnail);
         // }
       }


        $elementTexts = array();
        $elements = array('contributor', 'coverage', 'creator',
                          'date', 'description', 'format',
                          'identifier', 'language', 'publisher',
                          'relation', 'rights', 'source',
                          'subject', 'title', 'type');
        foreach ($elements as $element) {
            if (isset($dcMetadata->$element)) {
                foreach ($dcMetadata->$element as $rawText) {
                    $text = trim($rawText);
                    $elementTexts['Dublin Core'][ucwords($element)][]
                        = array('text' => (string) $text, 'html' => false);
                    // _log("[OaipmhHarvester] $element : " . trim($rawText), Zend_Log::INFO);
                }
            }
        }

        // If dc:identifier contains http link
        // we try to get targeted file for thumbnails generation
        $element = 'identifier';
        $fileMetadata = array();
        $fileMetadata['file_transfer_type'] = 'Url';
        $fileMetadata['options'] = array(
          'ignore_invalid_files' => true
        );
        if (isset($dcMetadata->$element)) {
          foreach ($dcMetadata->$element as $rawText) {
              $text = trim($rawText);
              ((strpos($text, 'http')  !== false) ? $url = substr($text, strpos($text, 'http')) : array());
              // options for ark:/ links thumbnail suffix are /lowres/medres/highres)
              ((strpos($text, 'ark:')  !== false) ? $url = substr($text, strpos($text, 'http')) . '.highres.jpg' : array());
          }
          if(isset($url)){
            $url = str_replace('https' , 'http' , $url);
            $fileMetadata['files'][] = array(
              'Upload' => null,
              'Url' => (string) $url ,
              'source' => (string) $url,
              // 'name'   => (string) $dcMetadata->title,
              // 'metadata' => (isset($issn) ? (string) $issn : array()),
            );
            _log("[OaipmhHarvester] Element / Found FILE : " . (string) $url, Zend_Log::INFO);
            $url = undefined;
          }
        }

        // If dc:relation contains http link
        // we try to get targeted file for thumbnails generation
        $element = 'relation';
        if (isset($dcMetadata->$element)) {
          foreach ($dcMetadata->$element as $rawText) {
              $text = trim($rawText);
              $extension = substr($text, strrpos($text, '.') + 1);
              ((strpos($text, 'http')  !== false) ? $url = substr($text, strpos($text, 'http')) : array());
              // options for ark:/ links thumbnail suffix are /lowres/medres/highres)
              ((strpos($text, 'ark:')  !== false && $extension  !== "thumbnail")  ? $url = substr($text, strpos($text, 'http')) . '.highres.jpg' : array());
          }
          if(strpos($url, 'archivesetmanuscrits.bnf.fr') == false && strpos($url, 'catalogue.bnf.fr') == false) {
            $url = str_replace('https' , 'http' , $url);
            $fileMetadata['files'][] = array(
              'Upload' => null,
              'Url' => (string) $url ,
              'source' => (string) $url,
            );
            _log("[OaipmhHarvester] Relation / Found FILE : " . (string) $url, Zend_Log::INFO);
          }
        }

        return array('itemMetadata' => $itemMetadata,
                     'elementTexts' => $elementTexts,
                     'fileMetadata' => $fileMetadata);
    }
}
