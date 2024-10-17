await new Promise((resolve) => setTimeout(resolve, 10_000));

export default async (context) => {
  return context.res.json({
    cpus: process.env.OPEN_RUNTIMES_CPUS,
    memory: process.env.OPEN_RUNTIMES_MEMORY,
  });
};
