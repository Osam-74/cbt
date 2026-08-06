path = '/app/conversations/6a72ccad11d2460647b6e6f3/cbt_v2/educbt-pro/includes/Api/QuestionController.php'
with open(path, 'r') as f:
    content = f.read()

old_code = """        $service = new QuestionSetService();
        $result  = $service->add_question( $school_id, $set_id, $data );"""

new_code = """        $scope       = new Scope();
        $actor       = $scope->actor();
        $can_approve = Gate::allows( Capabilities::APPROVE_QUESTIONS );

        $service = new QuestionSetService();
        $result  = $service->add_question( $school_id, $set_id, $data, (int) $actor['id'], $can_approve );"""

assert old_code in content, 'Old code in QuestionController.php not found'
content = content.replace(old_code, new_code, 1)

with open(path, 'w') as f:
    f.write(content)

print('Updated QuestionController.php successfully')
