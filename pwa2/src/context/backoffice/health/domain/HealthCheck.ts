export interface HealthCheckData {
  status: string;
  service: string;
  datetime: string;
}

export class HealthCheck {
  constructor(
    public readonly status: string,
    public readonly service: string,
    public readonly datetime: string,
  ) {}

  static fromPrimitives(data: HealthCheckData): HealthCheck {
    return new HealthCheck(data.status, data.service, data.datetime);
  }
}
