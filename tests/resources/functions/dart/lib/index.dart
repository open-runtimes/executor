import 'dart:async';
import 'dart:convert';
import 'package:dio/dio.dart' hide Response;
import 'dart:io' show Platform;

Future<dynamic> main(final context) async {
  final id = context.req.body['id'] ?? '1';
  final todo = await Dio().get('https://dummyjson.com/todos/$id');
  context.log('Sample Log');

  return context.res.json({
    'isTest': true,
    'message': "Hello Open Runtimes 👋",
    'url': context.req.url,
    'variable': Platform.environment['TEST_VARIABLE'] ?? '',
    'todo': todo.data,
  });
}