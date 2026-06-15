<?php

return [

    'memory_stale_days' => (int) env('PULSE_MEMORY_STALE_DAYS', 14),

    'jira_days' => (int) env('PULSE_JIRA_DAYS', 14),

    'jira_follow_up_days' => (int) env('PULSE_JIRA_FOLLOW_UP_DAYS', 3),

    'meeting_action_days' => (int) env('PULSE_MEETING_ACTION_DAYS', 30),

    'max_memory_health' => (int) env('PULSE_MAX_MEMORY_HEALTH', 10),

    'max_commitments' => (int) env('PULSE_MAX_COMMITMENTS', 15),

    'max_jira' => (int) env('PULSE_MAX_JIRA', 15),

    'max_commitments_per_project' => (int) env('PULSE_MAX_COMMITMENTS_PER_PROJECT', 5),

];
