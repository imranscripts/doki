# Doki Playwright Runner
# Pre-built image with @playwright/test installed
# Browsers come from Microsoft's official Playwright image

FROM mcr.microsoft.com/playwright:v1.49.1-noble

# Install @playwright/test globally (version must match the base image's browser versions)
# and create output directories
RUN npm install -g @playwright/test@1.49.1 \
    && mkdir -p /output/report /output/results

# Set NODE_PATH so config files can import from @playwright/test
# This allows test projects without their own node_modules to work
ENV NODE_PATH=/usr/lib/node_modules

# Set working directory for test projects
WORKDIR /tests

# The global install adds 'playwright' to PATH
ENTRYPOINT ["playwright"]
CMD ["--help"]
