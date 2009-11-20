<?php
 
/**
 * Initialization file for parser function functionality in the Maps extension
 *
 * @file Maps_ParserFunctions.php
 * @ingroup Maps
 *
 * @author Jeroen De Dauw
 */

if( !defined( 'MEDIAWIKI' ) ) {
	die( 'Not an entry point.' );
}

/**
 * A class that holds handlers for the mapping parser functions.
 * 
 * @author Jeroen De Dauw
 */
final class MapsParserFunctions {
	
	/**
	 * Initialize the parser functions feature. This function handles the parser function hook,
	 * and will load the required classes.
	 */
	public static function initialize() {
		global $egMapsIP, $IP, $wgAutoloadClasses, $egMapsServices;
		
		include_once $egMapsIP . '/ParserFunctions/Maps_iDisplayFunction.php';
		
		foreach($egMapsServices as $serviceName => $serviceData) {
			// Check if the service has parser function support
			$hasPFs = array_key_exists('pf', $serviceData);
			
			// If the service has no parser function support, skipt it and continue with the next one.
			if (!$hasPFs) continue;
			
			// Go through the parser functions supported by the mapping service, and load their classes.
			foreach($serviceData['pf'] as $parser_name => $parser_data) {
				$file = $parser_data['local'] ? $egMapsIP . '/' . $parser_data['file'] : $IP . '/extensions/' . $parser_data['file'];
				$wgAutoloadClasses[$parser_data['class']] = $file;
			}
		}
	}
	
	/**
	 * Returns the output for the call to the specified parser function.
	 * 
	 * @param $parser
	 * @param array $params
	 * @param string $parserFunction
	 * 
	 * @return array
	 */
	public static function getMapHtml(&$parser, array $params, $parserFunction) {
        global $wgLang;
        
        array_shift( $params ); // We already know the $parser.
        
        $map = array();
        $coordFails = array();
        
        $geoFails = self::changeAddressesToCoords($params);        
        
        // Go through all parameters, split their names and values, and put them in the $map array.
        foreach($params as $param) {
            $split = explode('=', $param);
            if (count($split) > 1) {
                $paramName = strtolower(trim($split[0]));
                $paramValue = trim($split[1]);
                if (strlen($paramName) > 0 && strlen($paramValue) > 0) {
                	$map[$paramName] = $paramValue;
                	if (MapsMapper::inParamAliases($paramName, 'coordinates')) $coordFails = self::filterInvalidCoords($map[$paramName]);
                }
            }
            else if (count($split) == 1) { // Default parameter (without name)
            	$split[0] = trim($split[0]);
                if (strlen($split[0]) > 0) $map['coordinates'] = $split[0];
            }
        }
        
        $coords = MapsMapper::getParamValue('coordinates', $map);
        
        if ($coords) {
            if (! MapsMapper::paramIsPresent('service', $map)) $map['service'] = '';
            $map['service'] = MapsMapper::getValidService($map['service'], 'pf');                
    
            $mapClass = self::getParserClassInstance($map['service'], $parserFunction);
    
            // Call the function according to the map service to get the HTML output
            $output = $mapClass->displayMap($parser, $map);    

			if (count($coordFails) > 0) {
                $output .= '<i>' . wfMsgExt( 'maps_unrecognized_coords_for', array( 'parsemag' ), $wgLang->listToText( $coordFails ), count( $coordFails ) ) . '</i>';
            }            
            
            if (count($geoFails) > 0) {
                $output .= '<i>' . wfMsgExt( 'maps_geocoding_failed_for', array( 'parsemag' ), $wgLang->listToText( $geoFails ), count( $geoFails ) ) . '</i>';
            }
        }
        elseif (trim($coords) == "" && (count($geoFails) > 0 || count($coordFails) > 0)) {
        	if (count($coordFails) > 0) $output = '<i>' . wfMsgExt( 'maps_unrecognized_coords', array( 'parsemag' ), $wgLang->listToText( $coordFails ), count( $coordFails ) ) . '</i>';
            if (count($geoFails) > 0) $output = '<i>' . wfMsgExt( 'maps_geocoding_failed', array( 'parsemag' ), $wgLang->listToText( $geoFails ), count( $geoFails ) ) . '</i>';
            $output .= '<i>' . wfMsg('maps_map_cannot_be_displayed') .'</i>'; 
        }
        else {
            $output = '<i>'.wfMsg( 'maps_coordinates_missing' ).'</i>';
        }
        
        // Return the result
        return array( $output, 'noparse' => true, 'isHTML' => true ); 	
	}		
	
	/**
	 * Filters all non coordinate valus from a coordinate string, 
	 * and returns an array containing all filtered out values.
	 * 
	 * @param string $coordList
	 * @param string $delimeter
	 * 
	 * @return array
	 */
	private static function filterInvalidCoords(&$coordList, $delimeter = ';') {
		$coordFails = array();
		$validCoordinates = array();
        $coordinates = explode($delimeter, $coordList);
        
        foreach($coordinates as $coordinate) {
        	if (MapsGeocodeUtils::isCoordinate($coordinate)) {
        		$validCoordinates[] = $coordinate;
        	}
        	else {
        		$coordFails[] = $coordinate;
        	}
        }
        
        $coordList = implode($delimeter, $validCoordinates);  
        return $coordFails;
	}
	
	/**
	 * Changes the values of the address or addresses parameter into coordinates
	 * in the provided array. Returns an array containing the addresses that
	 * could not be geocoded.
	 *
	 * @param array $params
	 * 
	 * @return array
	 */
	private static function changeAddressesToCoords(&$params) {
		global $egMapsDefaultService;

		$fails = array();
		
		// Get the service and geoservice from the parameters, since they are needed to geocode addresses.
		for ($i = 0; $i < count($params); $i++) {
			$split = explode('=', $params[$i]);
			if (MapsMapper::inParamAliases(strtolower(trim($split[0])), 'service') && count($split) > 1) {
				$service = trim($split[1]);
			}
			else if (strtolower(trim($split[0])) == 'geoservice' && count($split) > 1) {
				$geoservice = trim($split[1]);
			}			
		}

		// Make sure the service and geoservice are valid.
		$service = isset($service) ? MapsMapper::getValidService($service, 'pf') : $egMapsDefaultService;
		if (! isset($geoservice)) $geoservice = '';
		
		// Go over all parameters.
		for ($i = 0; $i < count($params); $i++) {
			$split = explode('=', $params[$i]);
			$isAddress = (strtolower(trim($split[0])) == 'address' || strtolower(trim($split[0])) == 'addresses') && count($split) > 1;
			$isDefault = count($split) == 1;
			
			// If a parameter is either the default (no name), or an addresses list, extract all locations.
			if ($isAddress || $isDefault) {
				
				$address_srting = $split[count($split) == 1 ? 0 : 1];
				$addresses = explode(';', $address_srting);

				$coordinates = array();
				
				// Go over every location and attempt to geocode it.
				foreach($addresses as $address) {
					$args = explode('~', $address);
					$args[0] = trim($args[0]);
					
					if (strlen($args[0]) > 0) {
						$coords =  MapsGeocodeUtils::attemptToGeocode($args[0], $geoservice, $service, $isDefault);
						
						if ($coords) {
							$args[0] = $coords;
							$coordinates[] = implode('~', $args);
						}
						else {
							$fails[] = $args[0];
						}
					}
				}				
				
				// Add the geocoded result back to the parameter list.
				$params[$i] = implode(';', $coordinates);

			}
			
		}

		return $fails;
	}	
	
	/**
	 * Returns an instance of the class supporting the spesified mapping service for
	 * the also spesified parser function.
	 * 
	 * @param string $service
	 * @param string $parserFunction
	 * 
	 * @return class
	 */
	private static function getParserClassInstance($service, $parserFunction) {
		global $egMapsServices;
		return new $egMapsServices[$service]['pf'][$parserFunction]['class']();
	}		

}