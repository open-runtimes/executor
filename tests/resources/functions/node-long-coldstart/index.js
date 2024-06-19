await new Promise((resolve) => setTimeout(resolve, 10_000));

export default async (context) => {
  return context.res.send("OK");
};
