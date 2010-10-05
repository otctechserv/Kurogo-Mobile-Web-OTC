<?php

require_once realpath(LIB_DIR.'/Module.php');

class CalendarModule extends Module {
  protected $id = 'calendar';
  protected $timezone;
  
  private $searchOptions = array(
    array("phrase" => "in the next 7 days",   "offset" => 7),
    //array("phrase" => "in the next 15 days",  "offset" => 15),
    //array("phrase" => "in the next 30 days",  "offset" => 30),
    //array("phrase" => "in the past 15 days",  "offset" => -15),
    //array("phrase" => "in the past 30 days",  "offset" => -30),
    //array("phrase" => "this school term",     "offset" => "term"),
    //array("phrase" => "this school year",     "offset" => "year")
  );


  private function dayInfo($time, $offset=0) {
    $time += $offset * 24 * 60 * 60;
    return array(
      "weekday"       => date('l', $time),
      "month"         => date('F', $time),
      "month_3Let"    => date('M', $time),
      "day_num"       => date('j', $time),
      "year"          => date('Y', $time),
      "month_num"     => date('m', $time),
      "day_3Let"      => date('D', $time),
      "day_num_2dig"  => date('d', $time),
      "date"          => date('Y/m/d', $time),
      "time"          => strtotime(date("Y-m-d 12:00:00", $time))
    );
  }
  
  private function timeText($event) {
    if ($event->get_end() - $event->get_start() == -1) {
      return $event->get_range()->format('D M j').' '.date('g:i a', $event->get_start());
    }
    return $event->get_range()->format('D M j g:i a');
  }

  private function ucname($name) {
    $new_words = array();
    foreach(explode(' ', $name) as $word) {
      $new_word = array();
      foreach(explode('/', $word) as $sub_word) {
        $new_word[] = ucwords($sub_word);
      }
      $new_word = implode('/', $new_word);
      $new_words[] = $new_word;
    } 
    return implode(' ', $new_words);
  }

  private function searchDates($option) {
    $offset = $this->options[$option]["offset"];
    $time = time();
    $day1 = dayInfo($time);

    if(is_int($offset)) {
      $day2 = dayInfo($time, $offset);
      if($offset > 0) {
        return array("start" => $day1['date'], "end" => $day2['date']);
      } else {
        return array("start" => $day2['date'], "end" => $day1['date']); 
      }
    } else {
      switch($offset) {
        case "term":
          if($day1['month_num'] < 7) {
            $endDate = "{$day1['year']}/07/01";
      } else {
            $endDate = "{$day1['year']}/12/31";
          }
          break;

        case "year": 
          if($day1['month_num'] < 7) {
            $endDate = "{$day1['year']}/07/01";
      } else {
            $year = $day1['year'] + 1;
            $endDate = "$year/07/01";
          }
          break;
      }    
      return array("start" => $day1['date'], "end" => $endDate); 
    }
  }
  
  private function valueForType($type, $value) {
    $valueForType = $value;
  
    switch ($type) {
      case 'datetime':
        if (is_a($value, 'DayRange')) {
          $valueForType = strval($value);
        } else {
          $valueForType = date("D M j", $value->get_start());
          if ($value->get_end() && $value->get_end()!=$value->get_start()) {
            if (date('Ymd', $value->get_start()) != date('Ymd', $value->get_end())) {
              $valueForType .= date(' g:i', $value->get_start());
              if (date('a', $value->get_start()) != date('a', $value->get_end())) {
                $valueForType .= date(' a', $value->get_start());
              }
        
              $valueForType .= date(" - D M j g:i a", $value->get_end());
            } else {
              $valueForType .= "<br/>" . date('g:i', $value->get_start()) . date("-g:i a", $value->get_end());
            }
          } else {
            $valueForType .= "<br/>" . date('g:i a', $value->get_start());
          }
        }
        
        break;

      case 'url':
        $valueForType = str_replace("http://http://", "http://", $value);
        if (strlen($valueForType) && !preg_match('/^http\:\/\//', $valueForType)) {
          $valueForType = 'http://'.$valueForType;
        }
        break;
        
      case 'phone':
        // add the local area code if missing
        if (preg_match('/^\d{3}-\d{4}/', $value)) {
          $valueForType = $GLOBALS['siteConfig']->getVar('LOCAL_AREA_CODE').$value;
        }
        $valueForType = str_replace('-', '-&shy;', str_replace('.', '-', $value));
        break;
      
      case 'email':
        $valueForType = str_replace('@', '@&shy;', $value);
        break;
        
      case 'category':
        $valueForType = $this->ucname($value);
        break;
    }
    
    return $valueForType;
  }
  
  private function urlForType($type, $value) {
    $urlForType = null;
  
    switch ($type) {
      case 'url':
        $urlForType = str_replace("http://http://", "http://", $value);
        if (strlen($urlForType) && !preg_match('/^http\:\/\//', $urlForType)) {
          $urlForType = 'http://'.$urlForType;
        }
        break;
        
      case 'phone':
        // add the local area code if missing
        if (preg_match('/^\d{3}-\d{4}/', $value)) {
          $urlForType = $GLOBALS['siteConfig']->getVar('LOCAL_AREA_CODE').$value;
        }
    
        // remove all non-word characters from the number
        $urlForType = 'tel:1'.preg_replace('/\W/', '', $value);
        break;
        
      case 'email':
        $urlForType = "mailto:$value";
        break;
        
      case 'category':
        $urlForType = $this->categoryURL($value, false);
        break;
    }
    
    return $urlForType;
  }

  // URL DEFINITIONS
  private function dayURL($day, $type, $addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('day', array(
      'time' => $day['time'],
      'type' => $type,
    ), $addBreadcrumb);
  }
  
  private function categoryDayURL($day, $categoryID, $name, $addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('category', array(
      'time' => $day['time'],
      'id'   => $categoryID,
      'name' => $name, 
    ), $addBreadcrumb);
  }
  
  private function academicURL($year, $addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('academic', array(
      'year' => $year,
    ), $addBreadcrumb);
  }
  
  private function holidaysURL($year=NULL, $addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('holidays', array(
      'year' => isset($year) ? $year : $this->args['year'],
    ), $addBreadcrumb);
  }
  
  private function religiousURL($year=NULL, $addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('holidays', array(
      'page' => 'religious',
      'year' => isset($year) ? $year : $this->args['year'],
    ), $addBreadcrumb);
  }
  
  private function categoriesURL($addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('categories', array(), $addBreadcrumb);
  }
  
  private function categoryURL($category, $addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('category', array(
      'id'   => is_array($category) ? $category['catid'] : $category->get_cat_id(),
      'name' => is_array($category) ? $category['name']  : $this->ucname($category->get_name()),
    ), $addBreadcrumb);
  }
  
  private function subCategorysURL($category, $addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('sub-categorys', array(
      'id' => is_array($category) ? $category['catid'] : $category->get_cat_id(),
    ), $addBreadcrumb);
  }
  
  private function detailURL($event, $addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('detail', array(
      'id' => $event->get_uid(),
      'time'=>$event->get_start()
//      'time' => isset($this->args['time']) ? $this->args['time'] : time(),
    ), $addBreadcrumb);
  }

  protected function initializeForPage() {
//      $GLOBALS['siteConfig']->loadThemeFile('calendar');
    $this->timezone = new DateTimeZone($GLOBALS['siteConfig']->getThemeVar('site', 'SITE_TIMEZONE'));

    switch ($this->page) {
      case 'help':
        break;
        
      case 'index':
        $today = $this->dayInfo(time());
      
        $this->assign('today',           $today);
        $this->assign('searchOptions',   $this->searchOptions);
        
        $this->assign('todaysEventsUrl', $this->dayURL($today, 'events'));
        $this->assign('holidaysUrl',     $this->holidaysURL($today['year']));
        $this->assign('categoriesUrl',    $this->categoriesURL());
        $this->assign('academicUrl',     $this->academicURL($today['year']));

        break;
      
      case 'categories':
        $this->setPageTitle("Browse by Category");
        $this->setBreadcrumbTitle("Category");

        $categories = array();
        $categoryObjects = Harvard_Calendar::get_categories($GLOBALS['siteConfig']->getVar('PATH_TO_EVENTS_CAT'));
        foreach ($categoryObjects as $categoryObject) {
          $categories[] = array(
            'title' => $this->ucname($categoryObject->get_name()),
            'url' => $this->categoryURL($categoryObject),
          );
        }
        
        $this->assign('categories', $categories);
        break;
      
      case 'category':
        $id   = self::argVal($this->args, 'id', '');
        $name = self::argVal($this->args, 'name', '');
        $time = self::argVal($this->args, 'time', time());

        $this->setPageTitle('Listing');
        $this->setBreadcrumbTitle($name);
        $this->setBreadcrumbLongTitle($name);

        $this->assign('category', $this->ucname($name));

        $dayRange = new DayRange(time());
        $next = $this->dayInfo($time, 1);
        $prev = $this->dayInfo($time, -1);
        
        $this->assign('current', $this->dayInfo($time));
        $this->assign('next',    $next);
        $this->assign('prev',    $prev);
        $this->assign('nextUrl', $this->categoryDayURL($next, $id, $name, false));
        $this->assign('prevUrl', $this->categoryDayURL($prev, $id, $name, false));
        $this->assign('isToday', $dayRange->contains(new TimeRange($time)));

        $events = array();
        
        if (strlen($id) > 0) {
          // copied from api/HarvardCalendar.php
          $start = date('Ymd', $time);
          $url = $GLOBALS['siteConfig']->getVar('HARVARD_EVENTS_ICS_BASE_URL').'?'.http_build_query(array(
            'startdate'    => $start,
            'days'         => 1,
            'filter1'      => $id,
            'filterfield1' => 15202,
          ));
          $iCalEvents = makeIcalDayEvents($url, $start, $id);
          
          foreach($iCalEvents as $iCalEvent) {
            $subtitle = $this->timeText($iCalEvent);
            $briefLocation = $iCalEvent->get_location();
            if (isset($briefLocation)) {
              $subtitle .= " | $briefLocation";
            }
          
            $events[] = array(
              'url'      => $this->detailURL($iCalEvent),
              'title'    => $iCalEvent->get_summary(),
              'subtitle' => $subtitle,
            );
          }
        }
        
        $this->assign('events', $events);        
        break;
      
      case 'day':  
        $this->setPageTitle("Browse by day");
        $this->setBreadcrumbTitle("Day");
      
        $type = isset($this->args['type']) ? $this->args['type'] : 'events';
        $this->assign('Type', ucwords($type));

        $time = isset($this->args['time']) ? $this->args['time'] : time();
        $next = $this->dayInfo($time, 1);
        $prev = $this->dayInfo($time, -1);
        $this->assign('current', $this->dayInfo($time));
        $this->assign('next',    $next);
        $this->assign('prev',    $prev);
        $this->assign('nextUrl', $this->dayURL($next, $type, false));
        $this->assign('prevUrl', $this->dayURL($prev, $type, false));
        
        $controllerClass = $GLOBALS['siteConfig']->getVar('CALENDAR_CONTROLLER_CLASS');
        $parserClass = $GLOBALS['siteConfig']->getVar('CALENDAR_PARSER_CLASS');
        $eventClass = $GLOBALS['siteConfig']->getVar('CALENDAR_EVENT_CLASS');
        $baseURL = $GLOBALS['siteConfig']->getVar('CALENDAR_ICS_URL');
        $feed = new $controllerClass($baseURL, new $parserClass, $eventClass);
        
        $start = new DateTime(date('Y-m-d H:i:s', $time), $this->timezone);
        $start->setTime(0,0,0);
        $end = clone $start;
        $end->setTime(23,59,59);

        $feed->setStartDate($start);
        $feed->setEndDate($end);
        $iCalEvents = $feed->items();
                
        $events = array();
        foreach($iCalEvents as $iCalEvent) {
          $subtitle = $this->timeText($iCalEvent);
          $briefLocation = $iCalEvent->get_location();
          if (isset($briefLocation)) {
            $subtitle .= " | $briefLocation";
          }
        
          $events[] = array(
            'url'      => $this->detailURL($iCalEvent),
            'title'    => $iCalEvent->get_summary(),
            'subtitle' => $subtitle
          );
        }
        $this->assign('events', $events);        
        break;
        
      case 'detail':  
        $this->setPageTitle("Detail");

        $this->loadThemeConfigFile('calendarDetail');
        $calendarFields = $this->getTemplateVars('calendarDetail');

        $controllerClass = $GLOBALS['siteConfig']->getVar('CALENDAR_CONTROLLER_CLASS');
        $parserClass     = $GLOBALS['siteConfig']->getVar('CALENDAR_PARSER_CLASS');
        $eventClass      = $GLOBALS['siteConfig']->getVar('CALENDAR_EVENT_CLASS');
        $baseURL         = $GLOBALS['siteConfig']->getVar('CALENDAR_ICS_URL');
        $feed = new $controllerClass($baseURL, new $parserClass, $eventClass);
        
        $time = isset($this->args['time']) ? $this->args['time'] : time();
        if ($event = $feed->getItem($this->args['id'], $time)) {
          $this->assign('event', $event);
        } else {
          throw new Exception("Event not found");
        }
    
        // build the list of attributes
        $allKeys = array_keys($calendarFields);

        $fields = array();
        foreach ($calendarFields as $key => $info) {
          if ($key == '*') {
            $skipKeys = $allKeys;
            if (isset($info['suppress'])) {
              $skipKeys = array_merge($skipKeys, $info['suppress']);
            }
            $extraKeys = array_diff($event->get_all_attributes(), $skipKeys);
            
            foreach ($extraKeys as $key) {
              if ($key != '*' && substr_compare($key, 'X-', 0, 2) != 0) {
                $fields[$key] = array(
                  'label' => $this->ucname($key),
                  'title' => $event->get_attribute($key),
                );  
              }
            }
            
          } else {
            $field = array();
            
            $value = $event->get_attribute($key);
            if (!isset($value)) { continue; }

            if (isset($info['label'])) {
              $field['label'] = $info['label'];
            }
            
            if (isset($info['class'])) {
              $field['class'] = $info['class'];
            }
            
            if (is_array($value)) {		
              $fieldValues = array();
              foreach ($value as $item) {
                $fieldValue = '';
                $fieldValueUrl = null;
                
                if (isset($info['type'])) {
                  $fieldValue  = $this->valueForType($info['type'], $item);
                  $fieldValueUrl = $this->urlForType($info['type'], $item);
                } else {
                  $fieldValue = $item;
                }
                
                if (isset($fieldValueUrl)) {
                  $fieldValue = '<a href="'.$fieldValueUrl.'">'.$fieldValue.'</a>';
                }
                
                $fieldValues[] = $fieldValue;
              }
              $field['title'] = implode(', ', $fieldValues);
            
            } else {
              if (isset($info['type'])) {
                $field['title'] = $this->valueForType($info['type'], $value);
                $field['url']   = $this->urlForType($info['type'], $value);
              } else {
                $field['title'] = nl2br($value);
              }
            }
            
            $fields[] = $field;
          }
        }
    
        $this->assign('fields', $fields);
        //error_log(print_r($fields, true));
    }
  }
}
