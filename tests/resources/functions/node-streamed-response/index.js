module.exports = async (context) => {
  context.res.start(201, { "x-start-header": "start" });
  context.res.writeText("Start");
  await new Promise((resolve) => {
    setTimeout(resolve, 1000);
  });
  context.res.writeText("Step1");
  await new Promise((resolve) => {
    setTimeout(resolve, 1000);
  });
  context.res.writeJson({ step2: true });
  await new Promise((resolve) => {
    setTimeout(resolve, 1000);
  });
  context.res.writeBinary(Buffer.from("0123456789abcdef", "hex"));
  return context.res.end({ "x-trainer-header": "end" });
};
