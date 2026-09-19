export type LoveTarget = "album" | "event" | "story";
export type LoveSummary = {
  count: number;
  loved_by_me: boolean;
  reactors: Array<{
    user_id: number;
    name: string;
    person: { id: string; name: string } | null;
  }>;
};
