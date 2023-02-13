import os
import json
import requests

def main(context):
    todo_id = context.req.body.get('id', 1)
    var_data = os.environ.get('TEST_VARIABLE', None)

    todo = (requests.get('https://jsonplaceholder.typicode.com/todos/' + str(todo_id))).json()

    context.log('Sample Log')

    return context.res.json({
        'isTest': True,
        'message': 'Hello Open Runtimes 👋',
        'todo': todo,
        'url': context.req.url,
        'variable': var_data
    })