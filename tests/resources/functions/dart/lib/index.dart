import 'dart:async';
import 'dart:convert';
import 'dart:io' show Platform;

Future<dynamic> main(final context) async {
  final id = context.req.body['id'] ?? '1';
  context.log('Sample Log');

  return context.res.json({
    'isTest': true,
    'message': "Hello Open Runtimes 👋",
    'url': context.req.url,
    'variable': Platform.environment['TEST_VARIABLE'] ?? '',
    'todo': {
      'id': int.parse(id.toString()),
      'todo': 'Use a local fixture for executor tests.',
      'completed': false,
      'userId': 13,
    },
  });
}
