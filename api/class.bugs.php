<?php
class Bugs Extends Http
{
	public $the_closer;
	public $the_opener;
	public $oldest;
	public $average;
	public $historical_average;
	public $assigned;
	public $priority;
	public $open_close;
	public $open_close_average;
	
	protected $config;
	protected $mantis_db;
	protected $dashboard_db;

	public function __construct () 
	{
		$core = Core::getInstance();
		
		$this->config       = $core->mantis->config;
		$this->mantis_db    = $core->mantis->db;
		$this->dashboard_db = $core->dashboard->db;
	}
  
	function open_close($days = 30)
	{
		$dates = array();
		$results = array();
		for($i = 0; $i < $days; $i++) 
		{
			$dates[] = date("Ymd", strtotime('-'. $i .' days'));
		}
		$open = $this->open_counts($days);
		$closed = $this->closed_counts($days);
		
		foreach ($dates as $index => $date) 
		{
			$results[$index] = array('open' => 0, 'closed' => 0);
			if (array_key_exists($date, $open)) 
			{
				$results[$index]['open'] = intval($open[$date]);
			}
			if (array_key_exists($date, $closed)) 
			{
				$results[$index]['closed'] = intval($closed[$date]);
			}
		}
		
		$results = array_reverse($results);
		
		$this->open_close = $results;
		
		return $this;
	}
	
	function open_close_average($days = 30)
	{
		$open = 0;
		$closed = 0;

		$this->open_close($days);
		$counts = $this->open_close;
		$this->open_close = NULL;
		foreach ($counts as $count)
		{
			$open += $count['open'];
			$closed += $count['closed'];
		}
		$open = $open / $days;
		$closed = $closed / $days;

		$this->open_close_average = array('open' => round($open), 'closed' => round($closed));
		
		return $this;
	}

	function open_counts($days)
	{		
		$query = "SELECT count(*) as count,
                  DATE_FORMAT(FROM_UNIXTIME(date_modified),'%Y%m%d') as `day`
                  FROM mantis_bug_history_table
                  WHERE mantis_bug_history_table.type = 1
                  GROUP BY `day`
                  ORDER BY `day` DESC
                  LIMIT :days";
				  
		$core = Core::getInstance();
		$query = $core->mantis->db->prepare($query);
		$query->bindParam(':days', $days, PDO::PARAM_INT);
		$query->execute();
		$results = $query->fetchAll(PDO::FETCH_ASSOC);
		return $this->format_days($results);
	}

	function closed_counts($days)
	{
		$query = "SELECT count(*) as count,
		          DATE_FORMAT(FROM_UNIXTIME(date_modified),'%Y%m%d') as `day`
		          FROM mantis_bug_history_table
		          WHERE mantis_bug_history_table.new_value = 90
		          GROUP BY `day`
		          ORDER BY `day` DESC
		          LIMIT :days";
				  
  		$core = Core::getInstance();
  		$query = $core->mantis->db->prepare($query);
		$query->bindParam(':days', $days, PDO::PARAM_INT);
		$query->execute();
		$results = $query->fetchAll(PDO::FETCH_ASSOC);
		return $this->format_days($results);
	}
	
	function format_days($results)
	{
		$formatted = array();
		foreach ($results as $result) {
			$formatted[$result['day']] = $result['count'];
		}
		return $formatted;
	}
	
	function the_closer()
	{
		$exclude = $this->config['excluded_projects'];
		$date_start = strtotime(date("Y-m-d") . ' 00:00:00');
		$date_end   = strtotime(date("Y-m-d") . ' 23:59:59');
		
		$query = "SELECT COUNT(*) AS closed, mantis_bug_history_table.user_id, mantis_user_table.username, mantis_user_table.email, mantis_bug_table.project_id
				  FROM mantis_bug_history_table
				  INNER JOIN mantis_user_table 
				  ON mantis_bug_history_table.user_id = mantis_user_table.id
				  INNER JOIN mantis_bug_table 
				  ON mantis_bug_history_table.bug_id = mantis_bug_table.id
				  WHERE date_modified >= '$date_start' AND date_modified <= '$date_end'
				  AND mantis_bug_history_table.new_value = 90
				  AND mantis_bug_table.project_id NOT IN ($exclude) 
				  GROUP BY user_id 
				  ORDER BY closed DESC 
				  LIMIT 1";
				
		$query = $this->mantis_db->prepare($query);
		$query->execute();

		$result = $query->fetchAll(PDO::FETCH_ASSOC);
		
		if (count($result) > 0)
		{
			$this->the_closer = $result;
			$this->the_closer[0]['email_md5'] = md5($result[0]['email']);
		}
		else
		{
			$this->the_closer = 'FALSE';
		}
		return $this;
	}
	
	function the_opener()
	{
		
		$exclude = $this->config['excluded_projects'];
		$date_start = strtotime(date("Y-m-d") . ' 00:00:00');
		$date_end   = strtotime(date("Y-m-d") . ' 23:59:59');
		
		$query = "SELECT COUNT(*) AS opened, mantis_bug_history_table.user_id, mantis_user_table.username, mantis_user_table.email, mantis_bug_table.project_id
				  FROM mantis_bug_history_table
				  INNER JOIN mantis_user_table 
				  ON mantis_bug_history_table.user_id = mantis_user_table.id
				  INNER JOIN mantis_bug_table 
  				  ON mantis_bug_history_table.bug_id = mantis_bug_table.id
				  WHERE date_modified >= '$date_start' AND date_modified <= '$date_end'
				  AND mantis_bug_history_table.type = 1 
				  AND mantis_bug_table.project_id NOT IN ($exclude) 
				  GROUP BY user_id 
				  ORDER BY opened DESC 
				  LIMIT 1";
		
		$query = $this->mantis_db->prepare($query);
		
		$query->execute();

		$result = $query->fetchAll(PDO::FETCH_ASSOC);
		
		if (count($result) > 0)
		{
			$this->the_opener = $result;
			$this->the_opener[0]['email_md5'] = md5($result[0]['email']);
		}
		else
		{
			$this->the_opener = 'FALSE';
		}
		return $this;
	}
	
	function oldest($count = 4)
	{
		$exclude = $this->config['excluded_projects'];
		
		$days = "SELECT mantis_bug_table.date_submitted, mantis_bug_table.handler_id, mantis_bug_table.bug_text_id, mantis_user_table.username, mantis_user_table.email
		FROM mantis_bug_table 
		INNER JOIN mantis_user_table 
		ON mantis_bug_table.handler_id = mantis_user_table.id
		WHERE status='50' 
		AND project_id NOT IN ($exclude) 
		ORDER BY date_submitted 
		LIMIT $count";
		$result = $this->mantis_db->query($days);
		
		$i = 0;
		foreach ($result as $row)
		{
			
			$timestamp  = $row['date_submitted'];
			$now        = time();
			$difference = $now - $timestamp;
			$hours      = $difference / 3600;
			$days       = $hours / 24;
			
			$retval[$i]['days'] = number_format($days, 0);
			$retval[$i]['handler'] = $row['username'];
			$retval[$i]['id'] = $row['bug_text_id'];
			$retval[$i]['email'] = $row['email'];
			$retval[$i]['email_md5'] = md5($row['email']);
			
			$i++;
		}
		
		$this->oldest = $retval;
        return $this;
	}
	
	function average()
	{
		$exclude = $this->config['excluded_projects'];
		
		$days = "SELECT date_submitted 
		FROM mantis_bug_table 
		WHERE status in ('10', '20', '30', '40', '50') 
		AND project_id NOT IN ($exclude)";
		$result = $this->mantis_db->query($days);

		foreach ($result as $row)
		{
			$timestamp = $row['date_submitted'];
			$now       = time();
			$difference[] = $now - $timestamp;
		}

		$retval['seconds'] = array_sum($difference) / count(array_filter($difference));
		$retval['hours']   = $retval['seconds'] / 3600;
		$retval['days'] = $retval['hours'] / 24;
		
		foreach ($retval as $key => $value)
		{
			$retval[$key] = number_format($value, 0);
		}

		$query ="INSERT INTO `bugs_average`
		SET id='', date=NOW(), average=?
		ON DUPLICATE KEY 
		UPDATE `average` = ?";
		
		$q = $this->dashboard_db->prepare($query);
		$q->execute(array($retval['days'], $retval['days']));
		
		$this->average = $retval;
        return $this;
	}
	
	function historical_average($days = 90)
	{
		$retval = array();
		
		$query = "SELECT average 
		FROM bugs_average 
		ORDER BY id DESC 
		LIMIT 0,$days";
		
		$query = $this->dashboard_db->query($query);
		$query->execute();
		$results = $query->fetchAll();
		
		foreach ($results as $result)
		{
			$retval[] = (int) $result['average'];
		}
		
		$retval = array_pad($retval, 90, 0);
		
		$retval = array_reverse($retval);
		
		$this->historical_average = $retval;
        return $this;
	}
	
	function assigned()
	{
		$employees = $this->config['employees'];
		$projects = $this->config['excluded_projects'];
		
		// Get developer info
		$info = $this->mantis_db->prepare("SELECT id, username, realname FROM mantis_user_table WHERE username IN ($employees)");
		$info->execute();
		$info_result = $info->fetchAll();
		foreach ($info_result as $key => $value)
		{
			// Count number of assigned bugs. Exclude some jobs
			$query = $this->mantis_db->prepare("SELECT COUNT(*) AS total FROM mantis_bug_table WHERE STATUS='50' AND handler_id = {$value['id']} AND project_id NOT IN ($projects)");
			$query->execute();
			$number = $query->fetchAll();
			$bugs_assigned[$value['username']] = $number[0]['total'];
		}

		foreach ($bugs_assigned as $key => $value)
		{
			$percent[$key] = $value / array_sum($bugs_assigned);
			$percent[$key] = round($percent[$key] * 100);
		}

		$max = max($percent);

		foreach ($percent as $key => $value)
		{
			$relative_percent[$key] = $value / $max; 
			$relative_percent[$key] = round($relative_percent[$key] * 100);
		}
		
		$retval = array(
			'relative_percent' => $relative_percent,
			'bugs_assigned' => $bugs_assigned
		);
		
		$this->assigned = $retval;
        return $this;
	}
	
	function priority()
	{
		$info = $this->mantis_db->prepare("SELECT COUNT(*) AS total FROM mantis_bug_table WHERE severity IN (60,70,80) AND status NOT IN (80, 90)");
		$info->execute();
		$info_result = $info->fetchAll();
		
		$this->priority = $info_result[0]['total'];
        return $this;
	}
}